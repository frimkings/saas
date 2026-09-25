<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconcileTenancy extends Command
{
    protected $signature = 'tenancy:reconcile {--json}';
    protected $description = 'Verify tenant assignments, branch ownership, inventory, and financial totals.';

    public function handle(): int
    {
        $tenantTables = [
            'patients', 'appointments', 'consultations', 'refractions', 'lens_orders', 'products',
            'branch_inventory_items', 'stock_movements', 'purchase_orders', 'sales', 'payment_transactions',
            'expenses', 'insurance_claims', 'settings', 'sms_logs', 'audit_trails',
        ];
        $report = ['orphans' => [], 'branch_mismatches' => [], 'totals' => []];
        foreach ($tenantTables as $table) {
            if (! Schema::hasTable($table)) continue;
            $report['orphans'][$table] = DB::table($table)->whereNull('clinic_id')->count();
            if (Schema::hasColumn($table, 'branch_id')) {
                $report['branch_mismatches'][$table] = DB::table($table.' as t')
                    ->join('branches as b', 'b.id', '=', 't.branch_id')
                    ->whereColumn('b.clinic_id', '!=', 't.clinic_id')->count();
            }
        }
        $report['totals'] = [
            'patients' => DB::table('patients')->count(),
            'product_legacy_quantity' => (int) DB::table('products')->sum('quantity'),
            'branch_inventory_quantity' => (int) DB::table('branch_inventory_items')->sum('quantity'),
            'sales_count' => DB::table('sales')->count(),
            'sales_total' => (string) DB::table('sales')->sum('total_amount'),
            'payments_total' => (string) DB::table('payment_transactions')->sum('amount'),
            'expenses_total' => (string) DB::table('expenses')->sum('amount'),
        ];
        $failures = array_sum($report['orphans']) + array_sum($report['branch_mismatches']);
        if ($report['totals']['product_legacy_quantity'] !== $report['totals']['branch_inventory_quantity']) $failures++;

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Failures'], collect($report['orphans'])->map(fn ($v, $k) => ['orphan '.$k, $v])->merge(
                collect($report['branch_mismatches'])->map(fn ($v, $k) => ['branch mismatch '.$k, $v])
            )->values()->all());
            foreach ($report['totals'] as $key => $value) $this->line($key.': '.$value);
        }
        $this->{$failures === 0 ? 'info' : 'error'}($failures === 0 ? 'Tenancy reconciliation passed.' : "Tenancy reconciliation failed ({$failures} issue(s)).");
        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
