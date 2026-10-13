<?php

namespace App\Livewire\Optical;

use App\Models\LensOrder;
use App\Livewire\Optical\Concerns\ManagesOrderPanel;
use Livewire\Component;
use Livewire\WithPagination;

class OpticalOrdersComponent extends Component
{
    use WithPagination, ManagesOrderPanel;

    public string $searchTerm = '';
    public string $statusFilter = '';

    /** Status chips: grouped statuses the list can be narrowed to. */
    public const FILTERS = [
        '' => 'All', 'Quotation' => 'Quotations', 'Pending' => 'Pending lab', 'lab' => 'At the lab',
        'ready' => 'Ready', 'due' => 'Balance due', 'Collected' => 'Collected', 'Cancelled' => 'Cancelled',
    ];

    // Narrowing filters (the status chips count within these).
    public string $dateField = 'created';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $sourceFilter = '';
    public string $partnerFilter = '';
    /** Staff member who created the order. */
    public string $creatorFilter = '';

    public const SOURCES = ['in_clinic' => 'In clinic', 'walk_in' => 'Walk-in', 'partner' => 'Partner clinic'];

    protected $queryString = [
        'searchTerm' => ['except' => ''], 'statusFilter' => ['except' => ''], 'dateField' => ['except' => 'created'],
        'dateFrom' => ['except' => ''], 'dateTo' => ['except' => ''], 'sourceFilter' => ['except' => ''], 'partnerFilter' => ['except' => ''],
        'creatorFilter' => ['except' => ''],
    ];

    public function updatingSearchTerm(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }

    public function updated($name): void
    {
        if (in_array($name, ['dateField', 'dateFrom', 'dateTo', 'sourceFilter', 'partnerFilter', 'creatorFilter'], true)) $this->resetPage();
        // A partner clinic only makes sense for partner jobs.
        if ($name === 'sourceFilter' && $this->sourceFilter !== 'partner') $this->partnerFilter = '';
    }

    public function datePreset(string $range): void
    {
        [$from, $to] = match ($range) {
            'today' => [today(), today()],
            '7d' => [today()->subDays(6), today()],
            'month' => [today()->startOfMonth(), today()],
            'lastmonth' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
            default => [null, null],
        };
        $this->dateFrom = $from?->toDateString() ?? '';
        $this->dateTo = $to?->toDateString() ?? '';
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->statusFilter = $filter;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['searchTerm', 'dateField', 'dateFrom', 'dateTo', 'sourceFilter', 'partnerFilter', 'creatorFilter']);
        $this->setFilter('');
    }

    /** Search, dates, source, partner and creator: everything except the status chip. */
    private function baseQuery()
    {
        $column = $this->dateField === 'pickup' ? 'pickUpDate' : 'created_at';
        return LensOrder::query()
            ->when($this->searchTerm, function ($query) {
                $term = '%'.$this->searchTerm.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('order_id', 'like', $term)
                        ->orWhere('frame_model_number', 'like', $term)
                        ->orWhere('customer_name', 'like', $term)
                        ->orWhere('customer_phone', 'like', $term)
                        ->orWhere('partner_clinic_name', 'like', $term)
                        ->orWhereHas('serviceLines', fn ($line) => $line->where('description', 'like', $term))
                        ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $term)->orWhere('contact', 'like', $term))
                        ->orWhereHas('refraction.consultation.patient', fn ($p) => $p->where('name', 'like', $term));
                });
            })
            ->when($this->validDate($this->dateFrom), fn ($q) => $q->whereDateIndexed($column, '>=', $this->dateFrom))
            ->when($this->validDate($this->dateTo), fn ($q) => $q->whereDateIndexed($column, '<=', $this->dateTo))
            ->when(array_key_exists($this->sourceFilter, self::SOURCES), fn ($q) => $q->where('order_source', $this->sourceFilter))
            ->when($this->sourceFilter === 'partner' && $this->partnerFilter !== '', fn ($q) => $q->where('partner_clinic_id', (int) $this->partnerFilter))
            ->when(ctype_digit($this->creatorFilter), fn ($q) => $q->where('user_id', (int) $this->creatorFilter));
    }

    private function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    private function applyFilter($query, string $filter)
    {
        return match ($filter) {
            '' => $query,
            'lab' => $query->whereIn('status', LensOrder::AT_LAB),
            'ready' => $query->whereIn('status', LensOrder::READY),
            'due' => $query->balanceDue(),
            default => $query->where('status', $filter),
        };
    }

    public function openCreateOrderModal()
    {
        return redirect()->route('optical.orders.create');
    }

    public function render()
    {
        $orders = $this->baseQuery()
            ->with(['patient', 'refraction.consultation.patient', 'user', 'frameProduct', 'lensProduct', 'serviceLines', 'lensLines', 'remakeOf', 'refundLog', 'partnerClinic', 'purchaseOrderLines.purchaseOrder'])
            ->tap(fn ($query) => $this->applyFilter($query, $this->statusFilter))
            ->latest()->paginate(10);

        return view('livewire.optical.optical-orders-component', [
            'orders' => $orders,
            // Clinic spectacle orders are paid on the consultation bill; show that bill's figures.
            'clinicBills' => $orders->getCollection()->filter(fn ($o) => \App\Support\Optical\ClinicSpectacleBilling::appliesTo($o))
                ->mapWithKeys(fn ($o) => [$o->id => \App\Support\Optical\ClinicSpectacleBilling::summary($o->refraction)]),
            'filterCounts' => collect(array_keys(self::FILTERS))->mapWithKeys(fn ($key) => [$key => $this->applyFilter($this->baseQuery(), $key)->count()]),
            'partners' => $this->sourceFilter === 'partner' ? \App\Models\OpticalPartnerClinic::orderBy('name')->get(['id', 'name', 'is_active']) : collect(),
            // Staff who have created orders here, including any who have since left.
            'creators' => \App\Models\User::whereIn('id', LensOrder::query()->whereNotNull('user_id')->distinct()->select('user_id'))->orderBy('name')->get(['id', 'name']),
            'narrowed' => $this->searchTerm !== '' || $this->dateFrom !== '' || $this->dateTo !== '' || $this->sourceFilter !== '' || $this->creatorFilter !== '',
            'notifier' => app(\App\Services\OpticalCollectionNotifier::class),
        ])
            ->layout('layouts.optical');
    }
}
