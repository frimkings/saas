<?php

namespace App\Http\Controllers;

use App\Services\Finance\CombinedStatementService;
use App\Services\Finance\StatementExporter;
use App\Support\FinanceStatements;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CombinedStatementExportController extends Controller
{
    public function __invoke(Request $request, string $format, CombinedStatementService $service, StatementExporter $exporter)
    {
        abort_unless(FinanceStatements::canViewCombined($request->user()), 403);
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($data['to'] ?? now()->toDateString());
        if ($from->gt($to)) [$from, $to] = [$to, $from];

        $statement = $service->statement($from, $to);
        $document = [
            'title' => 'Combined Statement — Clinic & Optical',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'generated_at' => now(),
            'generated_by' => $request->user()?->name ?? 'System',
            'status' => null,
            'rows' => $service->exportRows($statement),
            'notes' => [
                'Each business is worked out by its own statement: the clinic counts revenue at the sale, optical when a job is ordered.',
                'Tax is the tax on the clinic income statement'.($statement['clinicTaxRate'] > 0 ? ' ('.number_format($statement['clinicTaxRate'], 2).'% of clinic profit)' : '').'. Optical has no tax setting, so no tax is worked out on optical profit.',
            ],
        ];
        $filename = 'combined-statement-'.$from->toDateString().'-to-'.$to->toDateString();

        return $format === 'pdf' ? $exporter->pdf($document, $filename) : $exporter->csv($document, $filename);
    }
}
