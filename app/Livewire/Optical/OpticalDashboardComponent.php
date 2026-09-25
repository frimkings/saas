<?php

namespace App\Livewire\Optical;

use App\Models\LensOrder;
use App\Models\OpticalProduct;
use App\Models\Sales;
use App\Models\Patient;
use Livewire\Component;

class OpticalDashboardComponent extends Component
{
    public function render()
    {
        // 1. Today's orders count
        $todaysOrdersCount = LensOrder::whereDate('created_at', now()->today())->count();

        // 2. Ready for collection count & list
        $readyOrders = LensOrder::with(['patient', 'refraction.consultation.patient', 'user', 'frameProduct', 'lensProduct', 'serviceLines'])
            ->where('status', 'Ready for Collection')
            ->latest()
            ->take(10)
            ->get();
        $readyCount = $readyOrders->count();

        // 3. Outstanding balances
        $outstandingTotal = LensOrder::whereNotIn('status', ['Quotation', 'Cancelled'])
            ->selectRaw('SUM(CASE WHEN frame_price + lens_price + glazing_fee + service_total - discount_amount > paid_amount THEN frame_price + lens_price + glazing_fee + service_total - discount_amount - paid_amount ELSE 0 END) as total_debt')
            ->value('total_debt') ?? 0;

        $outstandingCount = LensOrder::whereRaw('(frame_price + lens_price + glazing_fee + service_total - discount_amount) > paid_amount')
            ->whereNotIn('status', ['Quotation', 'Cancelled'])
            ->count();

        // 4. Low stock items
        $lowStockProducts = OpticalProduct::with(['category', 'stocks'])->where('is_active', true)
            ->get()->filter(fn ($product) => ($product->stocks->first()?->quantity ?? 0) <= ($product->stocks->first()?->reorder_level ?? 5))->take(5);
        $lowStockCount = $lowStockProducts->count();

        // 5. Recent activity
        $recentOrders = LensOrder::with(['patient', 'refraction.consultation.patient', 'serviceLines'])
            ->latest()
            ->take(6)
            ->get();

        return view('livewire.optical.optical-dashboard-component', [
            'todaysOrdersCount' => $todaysOrdersCount,
            'readyOrders' => $readyOrders,
            'readyCount' => $readyCount,
            'outstandingTotal' => $outstandingTotal,
            'outstandingCount' => $outstandingCount,
            'lowStockProducts' => $lowStockProducts,
            'lowStockCount' => $lowStockCount,
            'recentOrders' => $recentOrders,
            'overdueCount' => app(\App\Services\OpticalJobTrackingService::class)->overdue()->count(),
            'stuckCount' => app(\App\Services\OpticalJobTrackingService::class)->stuck()->count(),
        ])->layout('layouts.optical');
    }
}
