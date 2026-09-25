<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LensOrder;
use App\Models\OpticalOrderLensLine;
use App\Models\OpticalProduct;
use App\Models\OpticalProductStockMovement;
use App\Models\OpticalPurchaseOrderLine;
use App\Models\OpticalSupplierReturn;
use App\Models\PaymentTransaction;
use App\Models\RefundLog;
use App\Models\SaleItem;
use App\Models\Sales;
use Carbon\CarbonInterface;

/**
 * Optical profit and loss for a period, from optical data only (clinic books are separate).
 *
 * Revenue is counted when a job is ordered and when a retail sale is made, as in the
 * optical reports. Cost of sales uses what each item cost when it was sold (today's cost
 * price only for records from before that was kept); special-order lenses use what was
 * paid on the supplier order.
 */
class OpticalProfitService
{
    /** @return array<string, mixed> */
    public function statement(CarbonInterface $from, CarbonInterface $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $orders = LensOrder::with(['frameOpticalProduct', 'lensOpticalProduct'])
            ->whereBetween('created_at', $range)->whereNotIn('status', ['Quotation', 'Cancelled'])->get();
        $retailSales = Sales::where('business_line', 'optical')->where('transaction_id', 'like', 'OPOS-%')
            ->whereBetween('created_at', $range)->where('is_refunded', false)->get();
        $cancellationFees = (float) LensOrder::where('status', 'Cancelled')->whereBetween('cancelled_at', $range)->sum('cancellation_fee');

        $revenue = [
            'Frames' => (float) $orders->sum('frame_price'),
            'Lenses' => (float) $orders->sum('lens_price'),
            'Services & glazing' => (float) $orders->sum('service_total') + (float) $orders->sum('glazing_fee'),
            'Order discounts' => -(float) $orders->sum('discount_amount'),
            'Retail (POS)' => (float) $retailSales->sum('total_amount'),
            'Cancellation fees kept' => $cancellationFees,
        ];

        $costs = $this->costOfSales($orders, $retailSales);
        $losses = $this->stockLosses($range);
        $expenses = Expense::businessLine(Expense::OPTICAL)->with('category')
            ->whereBetween('expense_date', [$range[0]->toDateString(), $range[1]->toDateString()])->get();
        $operating = $expenses->filter(fn ($e) => ($e->category?->section ?? ExpenseCategory::OPERATING) !== ExpenseCategory::NON_OPERATING);
        $nonOperating = $expenses->diff($operating);
        $group = fn ($items) => $items->groupBy(fn ($e) => $e->category?->name ?? 'Uncategorised')->map(fn ($g) => round((float) $g->sum('amount'), 2))->sortDesc()->all();

        $totalRevenue = round(array_sum($revenue), 2);
        $totalCosts = round(array_sum($costs['lines']), 2);
        $grossProfit = round($totalRevenue - $totalCosts, 2);
        $totalLosses = round(array_sum($losses), 2);
        $totalOperating = round((float) $operating->sum('amount'), 2);
        $operatingProfit = round($grossProfit - $totalLosses - $totalOperating, 2);
        $totalNonOperating = round((float) $nonOperating->sum('amount'), 2);

        return [
            'revenue' => array_map(fn ($v) => round($v, 2), $revenue),
            'totalRevenue' => $totalRevenue,
            'costs' => array_map(fn ($v) => round($v, 2), $costs['lines']),
            'totalCosts' => $totalCosts,
            'uncostedFrames' => $costs['uncostedFrames'],
            'grossProfit' => $grossProfit,
            'grossMargin' => $totalRevenue > 0 ? round($grossProfit / $totalRevenue * 100, 1) : null,
            'losses' => array_map(fn ($v) => round($v, 2), $losses),
            'totalLosses' => $totalLosses,
            'operating' => $group($operating),
            'totalOperating' => $totalOperating,
            'operatingProfit' => $operatingProfit,
            'nonOperating' => $group($nonOperating),
            'totalNonOperating' => $totalNonOperating,
            'netProfit' => round($operatingProfit - $totalNonOperating, 2),
            'cash' => $this->cash($range),
            'jobs' => $orders->count(),
            'retailCount' => $retailSales->count(),
        ];
    }

    /** The statement as rows for the shared CSV/PDF export (see StatementExporter). */
    public function exportRows(array $pl): array
    {
        $rows = [];
        $section = function (string $heading, array $lines, ?string $totalLabel = null, ?float $total = null) use (&$rows) {
            $rows[] = ['type' => 'heading', 'label' => $heading];
            foreach ($lines as $label => $amount) $rows[] = ['type' => 'line', 'label' => $label, 'amount' => $amount];
            if ($totalLabel !== null) $rows[] = ['type' => 'total', 'label' => $totalLabel, 'amount' => $total];
        };
        $section('Revenue', $pl['revenue'], 'Total revenue', $pl['totalRevenue']);
        $section('Cost of sales', $pl['costs'], 'Total cost of sales', $pl['totalCosts']);
        $rows[] = ['type' => 'total', 'label' => 'Gross profit', 'amount' => $pl['grossProfit']];
        $section('Stock losses', $pl['losses'], 'Total stock losses', $pl['totalLosses']);
        $section('Operating expenses', $pl['operating'], 'Total operating expenses', $pl['totalOperating']);
        $rows[] = ['type' => 'total', 'label' => 'Operating profit', 'amount' => $pl['operatingProfit']];
        if ($pl['nonOperating']) $section('Non-operating expenses', $pl['nonOperating'], 'Total non-operating expenses', $pl['totalNonOperating']);
        $rows[] = ['type' => 'final', 'label' => 'Net profit', 'amount' => $pl['netProfit']];
        $rows[] = ['type' => 'heading', 'label' => 'Cash in the period'];
        $rows[] = ['type' => 'line', 'label' => 'Received', 'amount' => $pl['cash']['received']];
        $rows[] = ['type' => 'line', 'label' => 'Refunded', 'amount' => -$pl['cash']['refunded']];
        $rows[] = ['type' => 'total', 'label' => 'Net cash', 'amount' => $pl['cash']['net']];

        return $rows;
    }

    /** Totals kept with a period lock, to spot figures that move after the period is closed. */
    public function snapshot(array $pl): array
    {
        return array_intersect_key($pl, array_flip(['totalRevenue', 'totalCosts', 'grossProfit', 'totalLosses', 'totalOperating', 'totalNonOperating', 'netProfit']));
    }

    /** @return array{lines: array<string, float>, uncostedFrames: int} */
    private function costOfSales($orders, $retailSales): array
    {
        // The cost kept at sale time, falling back to the product's current cost price.
        $cost = fn (?OpticalProduct $product, int $quantity = 1, $recorded = null) => $recorded !== null
            ? $quantity * (float) $recorded
            : ($product ? $quantity * (float) $product->cost_price : 0.0);
        $frames = 0.0;
        $uncosted = 0;
        foreach ($orders as $order) {
            if ($order->frameOpticalProduct) $frames += $cost($order->frameOpticalProduct, 1, $order->frame_unit_cost);
            elseif ((float) $order->frame_price > 0) $uncosted++; // custom frame sold without a stock product
        }

        $orderIds = $orders->pluck('id');
        $stockLenses = OpticalOrderLensLine::with('product')->whereIn('lens_order_id', $orderIds)
            ->where('source', 'stock')->whereIn('status', ['held', 'consumed'])->get()
            ->sum(fn ($line) => $cost($line->product, 1, $line->unit_cost));
        $catalogueLenses = $orders->sum(fn ($order) => $cost($order->lensOpticalProduct, 1, $order->lens_optical_product_id ? $order->lens_unit_cost : null));
        $legacyAllocations = $orders->sum(fn ($order) => collect($order->lens_blank_allocations ?? [])
            ->sum(fn ($allocation) => ! empty($allocation['optical_product_id'])
                ? $cost(OpticalProduct::withTrashed()->find($allocation['optical_product_id']), (int) ($allocation['quantity'] ?? 0)) : 0));
        $specialOrder = OpticalPurchaseOrderLine::whereIn('lens_order_id', $orderIds)->get()
            ->sum(fn ($line) => $line->quantity_received * (float) $line->unit_cost);
        $retail = SaleItem::with('opticalProduct')->whereIn('sale_id', $retailSales->pluck('id'))->whereNotNull('optical_product_id')->get()
            ->sum(fn ($item) => $cost($item->opticalProduct, max(0, (int) $item->dispensed_quantity - (int) $item->refunded_quantity), $item->unit_cost));

        return ['lines' => [
            'Frames' => $frames,
            'Stock lenses used' => $stockLenses + $catalogueLenses + $legacyAllocations,
            'Special-order lenses bought' => $specialOrder,
            'Outside lab charges' => (float) $orders->sum('lab_cost'),
            'Retail items' => $retail,
        ], 'uncostedFrames' => $uncosted];
    }

    /** Stock lost (or found) in counts and returns the supplier did not credit. */
    private function stockLosses(array $range): array
    {
        $counts = OpticalProductStockMovement::where('movement_type', 'count')->whereBetween('created_at', $range)->get()
            ->sum(fn ($movement) => -$movement->quantity_change * (float) $movement->unit_cost);
        $writtenOff = (float) OpticalSupplierReturn::where('credit_status', 'written_off')->whereBetween('settled_at', $range)->sum('credit_expected');
        // Jobs cancelled after glazing: their lenses were cut and could not go back on the shelf.
        $scrapped = LensOrder::with(['lensOpticalProduct', 'lensLines.product'])->whereBetween('lenses_scrapped_at', $range)->get()
            ->sum(fn ($order) => $order->lensLines->where('source', 'stock')->where('status', 'consumed')
                    ->sum(fn ($line) => (float) ($line->unit_cost ?? $line->product?->cost_price))
                + ($order->lens_optical_product_id ? (float) ($order->lens_unit_cost ?? $order->lensOpticalProduct?->cost_price) : 0));
        return ['Stock count differences' => $counts, 'Supplier returns written off' => $writtenOff, 'Lenses cut for cancelled jobs' => $scrapped];
    }

    /** Money actually received and refunded on optical sales in the period. */
    private function cash(array $range): array
    {
        $opticalSales = Sales::where('business_line', 'optical')->select('id');
        $received = (float) PaymentTransaction::whereIn('sale_id', $opticalSales)->whereBetween('created_at', $range)->sum('amount');
        $refunded = (float) RefundLog::whereIn('sale_id', Sales::withTrashed()->where('business_line', 'optical')->select('id'))
            ->where('status', RefundLog::STATUS_PROCESSED)->whereBetween('processed_at', $range)->sum('refunded_amount');
        return ['received' => round($received, 2), 'refunded' => round($refunded, 2), 'net' => round($received - $refunded, 2)];
    }
}
