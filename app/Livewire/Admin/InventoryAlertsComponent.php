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
        $query = Product::with(['category', 'inventoryLots' => fn ($l) => $l->where('quantity', '>', 0)->orderBy('expiry_date')])->stocked()
            ->when($this->search, function ($query) {
                $search = '%' . $this->search . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', $search)
                        ->orWhere('batch_number', 'like', $search)
                        ->orWhereHas('category', fn ($cq) => $cq->where('name', 'like', $search));
                });
            });

        if ($this->activeTab === 'expired') {
            return $query->where(fn ($q) => $this->expiredWhere($q))->orderBy('expiry_date');
        }

        if ($this->activeTab === 'expiring') {
            return $query->where(fn ($q) => $this->expiringWhere($q))->orderBy('expiry_date');
        }

        return $query->needsReorder()->orderBy('quantity');
    }

    /** Expired: the product's own date, or any batch of it still holding stock. */
    private function expiredWhere($q)
    {
        return $q->whereDateIndexed('expiry_date', '<', Carbon::today())
            ->orWhereHas('inventoryLots', fn ($l) => $l->where('quantity', '>', 0)->whereDate('expiry_date', '<', Carbon::today()));
    }

    private function expiringWhere($q)
    {
        $until = Carbon::today()->addDays((int) $this->expiryWindow);

        return $q->where(fn ($own) => $own->whereDateIndexed('expiry_date', '>=', Carbon::today())->whereDateIndexed('expiry_date', '<=', $until))
            ->orWhereHas('inventoryLots', fn ($l) => $l->where('quantity', '>', 0)->whereDate('expiry_date', '>=', Carbon::today())->whereDate('expiry_date', '<=', $until));
    }

    public function render()
    {
        // Made-to-order products are never on the shelf, so they have no stock or expiry alerts.
        return view('livewire.admin.inventory-alerts-component', [
            'products' => $this->query()->paginate(20),
            'lowCount' => Product::needsReorder()->count(),
            'expiringCount' => Product::stocked()->where(fn ($q) => $this->expiringWhere($q))->count(),
            'expiredCount' => Product::stocked()->where(fn ($q) => $this->expiredWhere($q))->count(),
        ])->layout('layouts.admin.admin-layout');
    }
}
