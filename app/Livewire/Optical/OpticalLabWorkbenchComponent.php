<?php

namespace App\Livewire\Optical;

use App\Livewire\Optical\Concerns\ManagesOrderPanel;
use App\Models\LensOrder;
use App\Services\OpticalOrderWorkflowService;
use App\Support\OpticalAccess;
use Illuminate\Validation\ValidationException;
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

    /** Jobs ticked for a bulk action (ids), kept while paging; cleared when the list changes. */
    public array $selected = [];
    public bool $showBulkLab = false;
    public string $bulkLabSupplierId = '';
    public string $bulkExpectedBack = '';
    /** What the last bulk action did: ['title', 'done' => [...], 'skipped' => [...], 'note', 'labLink', 'labName']. */
    public ?array $bulkReport = null;

    public const BULK_LIMIT = 100;

    /** Bulk moves and the statuses each starts from (the same steps as the row buttons). */
    public const BULK_MOVES = [
        'In Production' => ['Start edging', ['Pending', 'Sent to Lab']],
        'Ready for Collection' => ['QC pass · ready', ['In Production']],
    ];

    public function updated($name): void
    {
        if (in_array($name, ['searchTerm', 'typeFilter'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function setStage(string $stage): void
    {
        abort_unless(array_key_exists($stage, self::STAGES), 422);
        $this->stage = $stage;
        $this->selected = [];
        $this->resetPage();
    }

    /** Every job the current stage, type and search show (not only this page), up to the limit. */
    public function selectAllMatching(): void
    {
        $this->selected = $this->benchQuery()->limit(self::BULK_LIMIT)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->showBulkLab = false;
    }

    public function dismissBulkReport(): void
    {
        $this->bulkReport = null;
    }

    /** The ticked jobs, in the order shown, limited and scoped to this shop. */
    private function selectedOrders()
    {
        $ids = collect($this->selected)->map(fn ($id) => (int) $id)->filter()->unique()->take(self::BULK_LIMIT)->all();

        return $ids ? LensOrder::whereIn('id', $ids)->orderByRaw('pickUpDate IS NULL')->orderBy('pickUpDate')->oldest()->orderBy('id')->get() : collect();
    }

    /**
     * Start edging or QC pass for every ticked job at that step. Each job goes through the same
     * workflow as its own button (lenses, special orders, the ready SMS); one that can't move is
     * skipped with the reason, and the rest carry on.
     */
    public function bulkMove(string $to): void
    {
        abort_unless(isset(self::BULK_MOVES[$to]), 422);
        abort_unless(OpticalAccess::canMoveTo(auth()->user(), $to), 403, 'Lab staff move jobs through the lab.');
        [$label, $from] = self::BULK_MOVES[$to];
        $workflow = app(OpticalOrderWorkflowService::class);
        $done = $skipped = [];
        $texted = $notTexted = 0;
        $keep = [];
        foreach ($this->selectedOrders() as $order) {
            if (! in_array($order->status, $from, true)) {
                $skipped[] = $order->order_id.': '.$order->status.', not at this step';
                $keep[] = (string) $order->id;
                continue;
            }
            try {
                $workflow->transition($order->id, $to);
                $done[] = $order->order_id;
                if ($workflow->lastReadySms !== null) ($workflow->lastReadySms['success'] ?? false) ? $texted++ : $notTexted++;
            } catch (ValidationException $e) {
                $skipped[] = $order->order_id.': '.collect($e->errors())->flatten()->first();
                $keep[] = (string) $order->id;
            }
        }
        // What could not move stays ticked, to fix and try again.
        $this->selected = $keep;
        $this->bulkReport = [
            'title' => $label, 'done' => $done, 'skipped' => $skipped,
            'note' => $to === 'Ready for Collection' && ($texted || $notTexted)
                ? $texted.' '.\Illuminate\Support\Str::plural('customer', $texted).' told by SMS'.($notTexted ? '; '.$notTexted.' SMS not sent (use WhatsApp from Awaiting Collection)' : '').'.'
                : null,
        ];
    }

    public function openBulkSendToLab(): void
    {
        abort_unless(OpticalAccess::bench(auth()->user()), 403, 'Lab staff do this.');
        $this->bulkLabSupplierId = '';
        $this->bulkExpectedBack = '';
        $this->resetValidation();
        $this->showBulkLab = true;
    }

    /**
     * Send every ticked job waiting to start to one lab, due back on one date (the lab's lead time
     * if left empty). Then one WhatsApp message to the lab lists them all.
     */
    public function bulkSendToLab(): void
    {
        abort_unless(OpticalAccess::bench(auth()->user()), 403, 'Lab staff do this.');
        $this->validate(['bulkLabSupplierId' => 'nullable|integer', 'bulkExpectedBack' => 'nullable|date|after_or_equal:today'],
            [], ['bulkLabSupplierId' => 'lab', 'bulkExpectedBack' => 'expected back date']);
        $workflow = app(OpticalOrderWorkflowService::class);
        $labId = $this->bulkLabSupplierId === '' ? null : (int) $this->bulkLabSupplierId;
        $done = $skipped = $sent = [];
        $keep = [];
        foreach ($this->selectedOrders() as $order) {
            if ($order->status !== 'Pending') {
                $skipped[] = $order->order_id.': '.$order->status.', already past this step';
                $keep[] = (string) $order->id;
                continue;
            }
            try {
                $sent[] = $workflow->transition($order->id, 'Sent to Lab', $labId, $this->bulkExpectedBack ?: null);
                $done[] = $order->order_id;
            } catch (ValidationException $e) {
                $skipped[] = $order->order_id.': '.collect($e->errors())->flatten()->first();
                $keep[] = (string) $order->id;
            }
        }
        $lab = $labId ? \App\Models\Supplier::find($labId) : null;
        $this->selected = $keep;
        $this->showBulkLab = false;
        $first = $sent[0] ?? null;
        $this->bulkReport = [
            'title' => 'Send to lab', 'done' => $done, 'skipped' => $skipped,
            'note' => $sent ? 'Sent to '.($lab?->name ?? 'the lab').($first?->expected_back_at ? ', due back '.$first->expected_back_at->format('d M') : '').'.' : null,
            'labLink' => $sent && $lab ? self::labMessageLink($lab, $sent) : null,
            'labName' => $lab?->name,
        ];
    }

    /** One WhatsApp message to the lab listing each job with its prescription, frame and lens. */
    public static function labMessageLink(\App\Models\Supplier $lab, array $orders): ?string
    {
        $lines = [];
        foreach (array_slice($orders, 0, 25) as $i => $order) {
            $rx = $order->prescription_snapshot ?? [];
            $eye = function (string $side) use ($rx) {
                $parts = array_filter([data_get($rx, "$side.sph"), data_get($rx, "$side.cyl"), filled(data_get($rx, "$side.axis")) ? 'x'.data_get($rx, "$side.axis") : null,
                    filled(data_get($rx, "$side.add")) ? 'add '.data_get($rx, "$side.add") : null], fn ($part) => filled($part));
                return $parts ? implode(' ', $parts) : null;
            };
            $details = $order->docketDetails();
            $lens = trim(implode(' ', array_filter([data_get($details, 'lens_details.type'), data_get($details, 'lens_details.index')])));
            $lines[] = ($i + 1).'. '.$order->order_id
                .(($r = $eye('od')) ? ' · R '.$r : '').(($l = $eye('os')) ? ' · L '.$l : '')
                .($lens !== '' ? ' · '.$lens : '').($order->frame_model_number ? ' · frame '.$order->frame_model_number : '');
        }
        if (count($orders) > 25) $lines[] = 'and '.(count($orders) - 25).' more.';
        $due = $orders[0]->expected_back_at ? ' We expect them back by '.$orders[0]->expected_back_at->format('j M').'.' : '';

        return \App\Support\Messaging\WhatsAppLink::to($lab->phone, 'Hello '.($lab->contact_person ?: $lab->name).', we are sending you '
            .count($orders).' '.\Illuminate\Support\Str::plural('job', count($orders)).':'."\n".implode("\n", $lines)."\n".$due
            .' — '.(app(\App\Support\Tenancy\TenantContext::class)->clinic()?->name ?? 'the optical shop'));
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
            ->with(['patient', 'refraction.consultation.patient', 'user', 'serviceLines', 'lensLines', 'partnerClinic', 'purchaseOrderLines.purchaseOrder']);

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

        // What each bulk action would apply to among the ticked jobs.
        $ticked = $this->selected ? $this->selectedOrders() : collect();
        $bulkCounts = [
            'In Production' => $ticked->whereIn('status', self::BULK_MOVES['In Production'][1])->count(),
            'Ready for Collection' => $ticked->whereIn('status', self::BULK_MOVES['Ready for Collection'][1])->count(),
            'Sent to Lab' => $ticked->where('status', 'Pending')->count(),
        ];

        return view('livewire.optical.optical-lab-workbench-component', [
            'orders' => $orders,
            'ticked' => $ticked,
            'bulkCounts' => $bulkCounts,
            'canBench' => OpticalAccess::bench(auth()->user()),
            'readySmsOn' => (bool) (\App\Models\OpticalSetting::first()?->ready_sms_auto ?? true),
            'labs' => $this->showBulkLab ? \App\Models\Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'lead_time_days']) : collect(),
            'stageCounts' => collect(array_keys(self::STAGES))->mapWithKeys(fn ($key) => [$key => $this->stageQuery($key)->count()]),
            'lateCount' => $lateCount,
        ])->layout('layouts.optical');
    }
}
