<?php

namespace App\Http\Controllers;

use App\Services\Attendance\AttendanceExporter;
use App\Services\Attendance\AttendancePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AttendanceExportController extends Controller
{
    public function __invoke(Request $request, string $format, AttendanceExporter $exporter)
    {
        // Two dates, or a whole month as older links have it.
        $data = $request->validate([
            'month' => 'required_without:from|date_format:Y-m',
            'from' => 'required_without:month|date_format:Y-m-d',
            'to' => 'required_with:from|date_format:Y-m-d|after_or_equal:from',
        ]);
        [$first, $last] = isset($data['from'])
            ? AttendancePeriod::resolve($data['from'], $data['to'], CarbonImmutable::now()->startOfDay())
            : AttendancePeriod::month($data['month']);

        if ($format === 'payroll') {
            // Pay is for the owner's eyes: not managers.
            abort_unless($request->user()?->hasRole('Super Admin') && \App\Services\LicenseService::has(\App\Support\Feature::ATTENDANCE), 403);

            return app(\App\Services\Attendance\AttendancePayroll::class)->csv($first, $last);
        }

        return $format === 'pdf' ? $exporter->pdf($first, $last) : $exporter->csv($first, $last);
    }
}
