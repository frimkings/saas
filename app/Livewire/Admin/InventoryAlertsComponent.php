<?php

namespace App\Livewire\Admin;

use App\Models\Product;
use Carbon\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class InventoryAlertsComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    public $activeTab = 'low';
    public $expiryWindow = 90;
    public $search = '';

    public function updatingActiveTab() { $this->resetPage(); }
    public function updatingExpiryWindow() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    private function query()
    {
        $query = Product::with('category')->stocked()
            ->when($this->search, function ($query) {
                $search = '%' . $this->search . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', $search)
                        ->orWhere('batch_number', 'like', $search)
                        ->orWhereHas('category', fn ($cq) => $cq->where('name', 'like', $search));
                });
            });

        if ($this->activeTab === 'expired') {
            return $query->whereDateIndexed('expiry_date', '<', Carbon::today())->orderBy('expiry_date');
        }

        if ($this->activeTab === 'expiring') {
            return $query->whereDateIndexed('expiry_date', '>=', Carbon::today())
                ->whereDateIndexed('expiry_date', '<=', Carbon::today()->addDays((int) $this->expiryWindow))
                ->orderBy('expiry_date');
        }

        return $query->where('quantity', '<=', 10)->orderBy('quantity');
    }

    public function render()
    {
        // Made-to-order products are never on the shelf, so they have no stock or expiry alerts.
        return view('livewire.admin.inventory-alerts-component', [
            'products' => $this->query()->paginate(20),
            'lowCount' => Product::stocked()->where('quantity', '<=', 10)->count(),
            'expiringCount' => Product::stocked()->whereDateIndexed('expiry_date', '>=', Carbon::today())
                ->whereDateIndexed('expiry_date', '<=', Carbon::today()->addDays((int) $this->expiryWindow))->count(),
            'expiredCount' => Product::stocked()->whereDateIndexed('expiry_date', '<', Carbon::today())->count(),
        ])->layout('layouts.admin.admin-layout');
    }
}
