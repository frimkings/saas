<?php

namespace App\Services\Attendance;

use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Models\AttendanceStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Stores a parsed attendance report for the current branch. The same month can be uploaded again as it fills up. */
class AttendanceImportService
{
    public function import(array $report, string $fileName): AttendanceImport
    {
        return DB::transaction(function () use ($report, $fileName) {
            $import = AttendanceImport::create([
                'file_name' => mb_substr($fileName, 0, 255),
                'period_start' => $report['period_start'],
                'period_end' => $report['period_end'],
                'exported_at' => $report['exported_at'],
                'staff_count' => count($report['staff']),
                'uploaded_by' => auth()->id(),
            ]);

            $added = 0;
            $accounts = null;
            foreach ($report['staff'] as $person) {
                // Names and departments follow the terminal; whether someone is tracked stays as set here.
                $staff = AttendanceStaff::updateOrCreate(
                    ['employee_no' => $person['employee_no']],
                    ['name' => $person['name'], 'department' => $person['department']],
                );
                if ($staff->wasRecentlyCreated) {
                    $accounts ??= User::query()->inCurrentClinic(['active'])->get(['id', 'name']);
                    $this->linkAccount($staff, $accounts);
                }
                foreach (array_chunk($person['punches'], 500) as $chunk) {
                    $added += DB::table('attendance_punches')->insertOrIgnore(array_map(fn (string $at) => [
                        'clinic_id' => $staff->clinic_id,
                        'branch_id' => $staff->branch_id,
                        'attendance_staff_id' => $staff->id,
                        'punched_at' => $at,
                        'attendance_import_id' => $import->id,
                    ], $chunk));
                }
            }

            $import->update(['new_punches' => $added]);

            return $import;
        });
    }

    /** Someone new on the terminal is linked to the one clinic user with the same name, unless that user is linked already. */
    public function linkAccount(AttendanceStaff $staff, $accounts): void
    {
        $matches = $accounts->filter(fn ($user) => self::plainName($user->name) === self::plainName($staff->name));
        if ($matches->count() !== 1 || AttendanceStaff::query()->where('user_id', $matches->first()->id)->exists()) {
            return;
        }
        $staff->update(['user_id' => $matches->first()->id]);
    }

    /** "Dr. Ama  MENSAH" -> "AMA MENSAH": names compared without titles, case, dots or extra spaces. */
    public static function plainName(string $name): string
    {
        $name = mb_strtoupper(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name)));

        return trim(preg_replace('/^(?:(?:DR|MR|MRS|MS|MISS|PROF)\s+)+/u', '', $name.' '));
    }
}
