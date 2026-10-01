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
        $todaysOrdersCount = LensOrder::whereDateIndexed('created_at', now()->today())->count();

        // 2. Ready for collection count & list
        $readyOrders = LensOrder::with(['patient', 'refraction.consultation.patient', 'user', 'frameProduct', 'lensProduct', 'serviceLines'])
            ->whereIn('status', LensOrder::READY)
            ->latest()
            ->take(10)
            ->get();
        $readyCount = LensOrder::whereIn('status', LensOrder::READY)->count();

        // 3. Outstanding balances: the same jobs as the Orders page's "Balance due" chip
        $outstandingTotal = (float) (LensOrder::balanceDue()
            ->selectRaw('SUM('.LensOrder::TOTAL_SQL.' - COALESCE(paid_amount,0)) as total_debt')
            ->value('total_debt') ?? 0);

        $outstandingCount = LensOrder::balanceDue()->count();

        // 4. Low stock items: count them all, list the first 5
        $lowStock = OpticalProduct::with(['category', 'stocks'])->where('is_active', true)
            ->get()->filter(fn ($product) => ($product->stocks->first()?->quantity ?? 0) <= ($product->stocks->first()?->reorder_level ?? 5));
        $lowStockProducts = $lowStock->take(5);
        $lowStockCount = $lowStock->count();

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
