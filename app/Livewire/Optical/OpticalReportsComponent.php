<?php

namespace App\Livewire\Optical;

use App\Models\LensOrder;
use App\Models\Sales;
use App\Models\SaleItem;
use Livewire\Component;

class OpticalReportsComponent extends Component
{
    public $fromDate;
    public $toDate;

    public function mount()
    {
        $this->fromDate = now()->startOfMonth()->format('Y-m-d');
        $this->toDate = now()->format('Y-m-d');
    }

    public function render()
    {
        $period = LensOrder::whereBetween('created_at', [$this->fromDate . ' 00:00:00', $this->toDate . ' 23:59:59'])
            ->whereNotIn('status', ['Quotation', 'Cancelled']);
        $totalOrders = (clone $period)->count();

        $orderRevenue = (clone $period)
            ->selectRaw('SUM(frame_price + lens_price + glazing_fee + service_total - discount_amount) as rev')
            ->value('rev') ?? 0;

        $orderCollected = (clone $period)
            ->sum('paid_amount');

        $retail = Sales::where('business_line', 'optical')->where('transaction_id', 'like', 'OPOS-%')
            ->whereBetween('created_at', [$this->fromDate . ' 00:00:00', $this->toDate . ' 23:59:59'])
            ->where('is_refunded', false);
        $retailRevenue = (clone $retail)->sum('total_amount');
        $retailCollected = (clone $retail)->sum('amount_paid');
        $retailCount = (clone $retail)->count();
        // Refunded orders are cancelled; any cancellation fee kept is still income.
        $range = [$this->fromDate . ' 00:00:00', $this->toDate . ' 23:59:59'];
        $refunded = LensOrder::with('refundLog')->where('status', 'Cancelled')->whereNotNull('refund_log_id')
            ->whereBetween('cancelled_at', $range)->get();
        $refundTotal = (float) $refunded->sum(fn ($order) => (float) $order->refundLog?->refunded_amount);
        $cancellationFees = (float) $refunded->sum('cancellation_fee');
        $remakes = LensOrder::with('lensLines.product')->whereNotNull('remake_of_id')
            ->whereNotIn('status', ['Quotation', 'Cancelled'])->whereBetween('created_at', $range)->get();
        $freeRemakes = $remakes->where('remake_charge', 'free');
        // Cost of free remakes: the lenses used, at cost price.
        $remakeLensCost = (float) $freeRemakes->sum(fn ($order) => $order->lensLines
            ->whereIn('status', ['held', 'consumed'])->sum(fn ($line) => (float) ($line->unit_cost ?? $line->product?->cost_price)));
        $remakeReasons = $remakes->countBy('remake_reason')->sortDesc();

        $totalRevenue = $orderRevenue + $retailRevenue + $cancellationFees;
        $totalCollected = $orderCollected + $retailCollected + $cancellationFees;
        $totalOutstanding = max(0, $orderRevenue - $orderCollected);
        $frameRevenue = (clone $period)->sum('frame_price');
        $lensRevenue = (clone $period)->selectRaw('SUM(lens_price + glazing_fee) as rev')->value('rev') ?? 0;
        $serviceRevenue = (clone $period)->sum('service_total');
        $discountTotal = (clone $period)->sum('discount_amount');
        $turnaround = (clone $period)->whereNotNull('collected_at')->get(['created_at', 'collected_at'])
            ->avg(fn ($order) => $order->created_at->diffInHours($order->collected_at) / 24);
        $topProducts = SaleItem::with('opticalProduct')
            ->whereNotNull('optical_product_id')
            ->whereBetween('created_at', [$this->fromDate . ' 00:00:00', $this->toDate . ' 23:59:59'])
            ->selectRaw('optical_product_id, SUM(dispensed_quantity - refunded_quantity) as units, SUM((dispensed_quantity - refunded_quantity) * selling_price) as gross')
            ->groupBy('optical_product_id')->havingRaw('SUM(dispensed_quantity - refunded_quantity) > 0')
            ->orderByDesc('gross')->limit(10)->get();

        return view('livewire.optical.optical-reports-component', [
            'totalOrders' => $totalOrders,
            'totalRevenue' => $totalRevenue,
            'totalCollected' => $totalCollected,
            'totalOutstanding' => $totalOutstanding,
            'orderRevenue' => $orderRevenue,
            'retailRevenue' => $retailRevenue,
            'retailCount' => $retailCount,
            'frameRevenue' => $frameRevenue,
            'lensRevenue' => $lensRevenue,
            'serviceRevenue' => $serviceRevenue,
            'discountTotal' => $discountTotal,
            'turnaround' => $turnaround,
            'topProducts' => $topProducts,
            'refundCount' => $refunded->count(),
            'refundTotal' => $refundTotal,
            'cancellationFees' => $cancellationFees,
            'remakeCount' => $remakes->count(),
            'freeRemakeCount' => $freeRemakes->count(),
            'remakeLensCost' => $remakeLensCost,
            'remakeRate' => $totalOrders > 0 ? $remakes->count() / $totalOrders * 100 : 0,
            'remakeReasons' => $remakeReasons,
        ])->layout('layouts.optical');
    }
}
