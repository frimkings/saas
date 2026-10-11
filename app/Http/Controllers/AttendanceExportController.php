<?php

namespace App\Http\Controllers;

use App\Services\Attendance\AttendanceExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AttendanceExportController extends Controller
{
    public function __invoke(Request $request, string $format, AttendanceExporter $exporter)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);
        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month']);

        return $format === 'pdf' ? $exporter->pdf($month) : $exporter->csv($month);
    }
}
