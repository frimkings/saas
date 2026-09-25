<?php

namespace App\Http\Controllers;

use App\Services\Finance\PeriodLockService;
use App\Services\Finance\StatementExporter;
use App\Services\OpticalProfitService;
use App\Support\BusinessLine;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OpticalProfitExportController extends Controller
{
    public function __invoke(Request $request, string $format, OpticalProfitService $service, StatementExporter $exporter)
    {
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($data['to'] ?? now()->toDateString());
        if ($from->gt($to)) [$from, $to] = [$to, $from];

        $pl = $service->statement($from, $to);
        $lock = app(PeriodLockService::class)->find(BusinessLine::OPTICAL, $from, $to);
        $notes = ['Optical business only. Jobs count when ordered; costs are what each item cost when it was sold, and special-order lenses what was paid for them.'];
        if ($pl['uncostedFrames'] > 0) $notes[] = "{$pl['uncostedFrames']} job(s) sold a custom frame with no stock product, so the frame cost is not included.";

        $document = [
            'title' => 'Optical Profit & Loss',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'generated_at' => now(),
            'generated_by' => $request->user()?->name ?? 'System',
            'status' => $lock ? 'Period locked '.$lock->locked_at?->format('M d, Y').($lock->lockedBy ? ' by '.$lock->lockedBy->name : '') : null,
            'rows' => $service->exportRows($pl),
            'notes' => $notes,
        ];
        $filename = 'optical-profit-and-loss-'.$from->toDateString().'-to-'.$to->toDateString();

        return $format === 'pdf' ? $exporter->pdf($document, $filename) : $exporter->csv($document, $filename);
    }
}
