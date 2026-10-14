<?php

namespace App\Http\Controllers;

use App\Models\AttendanceShift;
use App\Models\AttendanceStaff;
use App\Services\Attendance\AttendanceAnalyzer;
use App\Services\Attendance\AttendanceRosterFile;
use App\Services\LicenseService;
use App\Support\AttendanceScope;
use App\Support\Feature;
use App\Support\ShiftCodes;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** A department's roster as a file to fill in Excel (csv), or to print for the notice board (pdf). */
class AttendanceRosterController extends Controller
{
    public function __invoke(Request $request, string $format, AttendanceRosterFile $file, AttendanceAnalyzer $analyzer)
    {
        abort_unless(LicenseService::has(Feature::ATTENDANCE_SHIFTS), 403);
        $data = $request->validate([
            'department' => 'nullable|string|max:20',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);
        $first = CarbonImmutable::parse($data['from']);
        $last = min(CarbonImmutable::parse($data['to']), $first->addDays(AttendanceRosterFile::MAX_DAYS - 1));
        // The department asked for, within what this person may see: a head gets their own people only.
        $ids = AttendanceScope::staffIds($request->user(), (string) ($data['department'] ?? ''));
        $staff = AttendanceStaff::query()->where('is_tracked', true)->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))->orderBy('name')->get();
        $codes = ShiftCodes::for(AttendanceShift::query()->get());
        $context = app(TenantContext::class);
        $unit = ctype_digit((string) ($data['department'] ?? '')) ? \App\Models\AttendanceDepartment::query()->find((int) $data['department'])?->name : null;
        $name = 'roster-'.\Illuminate\Support\Str::slug($unit ?: 'all').'-'.$first->format('Y-m-d');

        if ($format === 'csv') {
            $content = $file->template($staff, $first, $last, $codes);

            return response()->streamDownload(fn () => print($content), $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $report = $analyzer->forStaff($staff->pluck('id')->all())->range($first, $last);
        $pdf = Pdf::loadView('pdf.attendance-roster', [
            'place' => trim(($context->clinic()?->name ?? '').' · '.($context->branch()?->name ?? ''), ' ·'),
            'unit' => $unit, 'first' => $first, 'last' => $last, 'report' => $report, 'codes' => $codes,
            'rows' => array_values(array_filter($report['rows'], fn ($row) => $row['staff']->is_tracked)),
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(fn () => print($pdf->output()), $name.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
