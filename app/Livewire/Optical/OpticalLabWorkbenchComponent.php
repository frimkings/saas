<?php

namespace App\Livewire\Optical;

use App\Livewire\Optical\Concerns\ManagesOrderPanel;
use App\Models\LensOrder;
use Livewire\Component;
use Livewire\WithPagination;

class OpticalLabWorkbenchComponent extends Component
{
    use WithPagination, ManagesOrderPanel;

    public string $searchTerm = '';
    public string $typeFilter = '';
    public string $stage = 'active';

    /** Workshop stages: the tiles at the top double as filters. */
    public const STAGES = [
        'active' => ['All open work', ['Pending', 'Sent to Lab', 'In Lab', 'In Production']],
        'queue' => ['Waiting to start', ['Pending', 'Sent to Lab', 'In Lab']],
        'bench' => ['On the bench', ['In Production']],
        'ready' => ['Ready for pickup', ['Ready for Collection', 'Ready']],
        'collected' => ['Collected today', ['Collected']],
    ];

    protected $queryString = ['searchTerm' => ['except' => ''], 'typeFilter' => ['except' => ''], 'stage' => ['except' => 'active']];

    public function updated($name): void
    {
        if (in_array($name, ['searchTerm', 'typeFilter'], true)) $this->resetPage();
    }

    public function setStage(string $stage): void
    {
        abort_unless(array_key_exists($stage, self::STAGES), 422);
        $this->stage = $stage;
        $this->resetPage();
    }

    private function stageQuery(string $stage)
    {
        $query = LensOrder::whereIn('status', self::STAGES[$stage][1]);
        return $stage === 'collected' ? $query->whereDate('collected_at', today()) : $query;
    }

    private function applyTypeAndSearch($query)
    {
        if ($this->typeFilter !== '') {
            $codes = match ($this->typeFilter) {
                'Glazing' => ['glazing', 'lens_fitting', 'custom_rx'],
                'Transfer' => ['lens_transfer'],
                'Repair' => ['frame_repair', 'frame_adjustment', 'custom_frame'],
                'Tinting' => ['custom'],
                default => [],
            };
            $query->where(function ($q) use ($codes) {
                $q->whereHas('serviceLines', fn ($line) => $line->whereIn('service_code', $codes));
                if ($this->typeFilter === 'Glazing') $q->orWhere('work_type', 'prescription');
            });
        }

        if ($this->searchTerm !== '') {
            $term = '%'.$this->searchTerm.'%';
            $query->where(function ($q) use ($term) {
                $q->where('order_id', 'like', $term)
                    ->orWhere('frame_model_number', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhere('partner_clinic_name', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhereHas('serviceLines', fn ($line) => $line->where('description', 'like', $term))
                    ->orWhereHas('patient', fn ($pq) => $pq->where('name', 'like', $term))
                    ->orWhereHas('refraction.consultation.patient', fn ($pq) => $pq->where('name', 'like', $term));
            });
        }

        return $query;
    }

    public function render()
    {
        $query = $this->applyTypeAndSearch($this->stageQuery($this->stage))
            ->with(['patient', 'refraction.consultation.patient', 'user', 'serviceLines', 'lensLines', 'partnerClinic']);

        // Open work: most urgent pickup first (no date last). Finished work: newest first.
        $orders = in_array($this->stage, ['active', 'queue', 'bench'], true)
            ? $query->orderByRaw('pickUpDate IS NULL')->orderBy('pickUpDate')->oldest()->paginate(12)
            : $query->latest('updated_at')->paginate(12);

        $lateCount = LensOrder::whereIn('status', self::STAGES['active'][1])->whereDate('pickUpDate', '<', today())->count();

        return view('livewire.optical.optical-lab-workbench-component', [
            'orders' => $orders,
            'stageCounts' => collect(array_keys(self::STAGES))->mapWithKeys(fn ($key) => [$key => $this->stageQuery($key)->count()]),
            'lateCount' => $lateCount,
        ])->layout('layouts.optical');
    }
}
