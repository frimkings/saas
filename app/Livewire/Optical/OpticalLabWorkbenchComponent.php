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
        return $stage === 'collected' ? $query->whereDateIndexed('collected_at', today()) : $query;
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

    /** The jobs shown for the current stage, type and search, in queue order. Also used by the bench sheet. */
    public function benchQuery()
    {
        $query = $this->applyTypeAndSearch($this->stageQuery($this->stage))
            ->with(['patient', 'refraction.consultation.patient', 'user', 'serviceLines', 'lensLines', 'partnerClinic']);

        // Open work: most urgent pickup first (no date last). Finished work: newest first.
        return in_array($this->stage, ['active', 'queue', 'bench'], true)
            ? $query->orderByRaw('pickUpDate IS NULL')->orderBy('pickUpDate')->oldest()
            : $query->latest('updated_at');
    }

    /** Printable bench sheet for every job matching the workbench filters, not only the current page. */
    public static function printSheet(\Illuminate\Http\Request $request)
    {
        $bench = new self;
        $bench->stage = array_key_exists((string) $request->query('stage'), self::STAGES) ? $request->query('stage') : 'active';
        $bench->typeFilter = in_array($request->query('typeFilter'), ['Glazing', 'Transfer', 'Repair', 'Tinting'], true) ? $request->query('typeFilter') : '';
        $bench->searchTerm = mb_substr(trim((string) $request->query('searchTerm')), 0, 100);
        $total = $bench->benchQuery()->count();

        return view('optical.lab-bench-sheet', [
            'orders' => $bench->benchQuery()->with(['frameOpticalProduct', 'frameProduct', 'lensOpticalProduct', 'remakeOf'])->limit(self::SHEET_LIMIT)->get(),
            'total' => $total,
            'stageLabel' => self::STAGES[$bench->stage][0],
            'typeFilter' => $bench->typeFilter,
            'searchTerm' => $bench->searchTerm,
        ]);
    }

    public const SHEET_LIMIT = 200;

    public function render()
    {
        $orders = $this->benchQuery()->paginate(12);

        $lateCount = LensOrder::whereIn('status', self::STAGES['active'][1])->whereDateIndexed('pickUpDate', '<', today())->count();

        return view('livewire.optical.optical-lab-workbench-component', [
            'orders' => $orders,
            'stageCounts' => collect(array_keys(self::STAGES))->mapWithKeys(fn ($key) => [$key => $this->stageQuery($key)->count()]),
            'lateCount' => $lateCount,
        ])->layout('layouts.optical');
    }
}
