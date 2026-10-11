<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceStaff;
use App\Models\User;
use App\Services\Attendance\AttendanceImportService;
use App\Services\Attendance\HikvisionEventReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Where an attendance terminal sends each event as it happens (its "HTTP listening" address).
 * The token in the address identifies the terminal and its branch. Punches are stored whatever
 * the clinic's subscription state, so attendance is not lost while a renewal is sorted out.
 */
class AttendancePushController extends Controller
{
    private const KEEP_UNREAD = 200;

    public function __invoke(Request $request, string $token, HikvisionEventReader $reader, AttendanceImportService $accounts): Response
    {
        $device = AttendanceDevice::withoutGlobalScopes()->where('token', $token)->where('is_active', true)->first();
        abort_unless($device, 404);
        $branch = $device->branch()->withoutGlobalScopes()->where('is_active', true)->first();
        $clinic = $branch?->clinic()->where('status', 'active')->first();
        $user = $branch?->users()->wherePivot('status', 'active')->orderBy('users.id')->first();
        abort_unless($clinic && $user, 404);
        app(TenantContext::class)->set($user, $clinic, $branch, [$branch->id]);

        $events = $request->isMethod('POST') ? $reader->events($request) : [];
        $punches = array_values(array_filter($events, [HikvisionEventReader::class, 'isPunch']));
        $stored = 0;
        foreach ($punches as $punch) {
            $staff = AttendanceStaff::firstOrCreate(['employee_no' => $punch['employee_no']], ['name' => $punch['name'] ?? 'Employee '.$punch['employee_no']]);
            if ($staff->wasRecentlyCreated) {
                $accounts->linkAccount($staff, User::query()->inCurrentClinic(['active'])->get(['id', 'name']));
            }
            // The terminal resends what was not acknowledged; a punch already held is skipped.
            $new = DB::table('attendance_punches')->insertOrIgnore([
                'clinic_id' => $clinic->id, 'branch_id' => $branch->id, 'attendance_staff_id' => $staff->id, 'punched_at' => $punch['time'],
                'terminal_status' => $punch['status'], 'method' => $punch['method'],
            ]);
            $stored += $new;
            if (! $new && ($punch['status'] || $punch['method'])) {
                // Held from before these were kept: fill them in when the terminal replays its history.
                DB::table('attendance_punches')->where('attendance_staff_id', $staff->id)->where('punched_at', $punch['time'])
                    ->update(['terminal_status' => $punch['status'], 'method' => $punch['method']]);
            }
        }

        // Every event moves the terminal's clock on, a punch or not: a quiet day is still a day it was working.
        $times = array_filter(array_column($events, 'time'));
        $changes = ['last_seen_at' => now(), 'punches_received' => $device->punches_received + $stored];
        if ($times) {
            $changes['first_event_at'] = min($device->first_event_at?->format('Y-m-d H:i:s') ?? '9999', min($times));
            $changes['last_event_at'] = max($device->last_event_at?->format('Y-m-d H:i:s') ?? '', max($times));
        }
        $device->forceFill($changes)->save();

        if ($request->isMethod('POST') && ! $events) {
            $this->keepUnread($request, $device);
        }

        // Anything but 200 makes the terminal send the same event again.
        return response('OK', 200);
    }

    /** A post nothing could be read from is kept (without any photo) to see what this firmware sends: `php artisan attendance:captures`. */
    private function keepUnread(Request $request, AttendanceDevice $device): void
    {
        DB::table('attendance_captures')->insert([
            'method' => $request->method(),
            'secure' => $request->secure(),
            'ip' => $request->ip(),
            'content_type' => mb_substr((string) $request->header('Content-Type'), 0, 255) ?: null,
            'headers' => json_encode(['x-attendance-device' => [(string) $device->id]] + array_diff_key($request->headers->all(), ['authorization' => 1, 'cookie' => 1])),
            'fields' => json_encode(array_map(fn ($value) => is_string($value) ? mb_strcut($value, 0, 65535) : $value, $request->post())),
            'files' => json_encode(array_keys($request->allFiles())),
            'body' => mb_strcut((string) $request->getContent(), 0, 65535) ?: null,
            'received_at' => now(),
        ]);
        $oldestKept = DB::table('attendance_captures')->orderByDesc('id')->skip(self::KEEP_UNREAD - 1)->value('id');
        if ($oldestKept) {
            DB::table('attendance_captures')->where('id', '<', $oldestKept)->delete();
        }
    }
}
