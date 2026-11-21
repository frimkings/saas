<?php

namespace App\Livewire\Secretary;

use App\Models\LensOrder;
use App\Models\Refractions;
use App\Models\Product;
use App\Models\Setting;
use App\Models\AuditTrail;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\SmsService;
use App\Models\SmsTemplate;

class SpectaclesComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    // Filters
    public $searchTerm   = '';
    public $fromDate;
    public $toDate;
    public $statusFilter = '';
    /** 21 Nov 2026: glasses ready at another branch of the clinic can be handed over here (search by name, number or order). */
    public string $otherBranchSearch = '';
    public $quickFilter  = '';
    public $labFilter    = '';
    public $doctorFilter = '';
    public $sortField      = 'created_at';
    public $sortDirection  = 'desc';
    public $activeRefractionId = null;
    public $recordType = 'orders';

    // Bulk selection: ticked rows by refraction id (a row is a refraction, with or without its order).
    public array $picked = [];
    public $bulkStatus = '';
    public $bulkPickUpDate = '';
    // Bulk send to an optical centre
    public bool $bulkSendOpen = false;
    public $bulkPartnerId = null;
    public string $bulkFrameChoice = 'patient_chooses';
    public string $bulkUrgency = 'normal';
    public string $bulkNotes = '';
    /** Rows a bulk action skipped, with why: [['name' => …, 'reason' => …]]. */
    public array $bulkSkipped = [];
    private bool $bulkRunning = false;

    // Inline editing
    public $editingOrderId;
    public $editField = [];

    // Order creation modal
    public $showOrderModal      = false;
    public $selectedRefractionId;
    public $selectedFrameId;
    public $selectedLensId;
    public $ownFrame            = false;
    public $ownFrameDescription = '';
    public $frameSearchTerm  = '';
    public $lensSearchTerm   = '';
    public $framePrice       = 0;
    public $lensPrice        = 0;
    public $pickUpDate;
    public $orderNotes;
    public $labName          = '';
    public $labReference     = '';
    public $labCost          = 0;
    public $statusOverrideReason = '';
    public $showFrameResults = false;
    public $showLensResults  = false;

    // Renewal date editing
    public $renewalEditOrderId = null;
    public $renewalEditDate    = '';

    // Cancel confirmation
    public $cancelConfirmId = null;
    public $cancelReason    = '';

    // Print preview
    public $showPrintPreview    = false;
    public $printPreviewOrderId = null;
    public $autoPrint           = false;

    protected $queryString = [
        'searchTerm',
        'statusFilter',
        'quickFilter',
        'doctorFilter',
        'labFilter',
    ];

    protected $listeners = ['updateOrderStatus'];

    /* =================== LIFECYCLE =================== */

    public function mount()
    {
        $this->fromDate = now()->subMonths(3)->format('Y-m-d');
        $this->toDate   = now()->format('Y-m-d');
        $this->bulkPickUpDate = now()->addDays(7)->format('Y-m-d');

        // Open where the work is: refractions waiting for an order, when no order is in progress.
        $waiting = Refractions::awaitingSpectacleOrder()->exists();
        if ($waiting && !LensOrder::whereIn('status', ['Pending', 'In Lab', 'Ready'])->exists()) {
            $this->recordType = 'refractions';
        }
    }

    /* =================== FILTERS =================== */

    public function updatedSearchTerm()  { $this->resetPage(); }
    public function updatedStatusFilter(){ $this->resetPage(); }
    public function updatedDoctorFilter(){ $this->resetPage(); }
    public function updatedLabFilter()   { $this->resetPage(); }

    /** Order statuses on the status bar; "To order" (no order yet) is the refractions list, not a status. */
    public const ORDER_STATUSES = ['Pending', 'In Lab', 'Ready', 'Collected', 'Cancelled'];

    /** The bar's warnings (overdue pickup, renewal due) are lists of orders: picking one again turns it off. */
    public function setQuickFilter($filter)
    {
        $this->clearSelection();
        if (! in_array($filter, ['overdue', 'renewal_due'], true)) return;
        $this->quickFilter = $this->quickFilter === $filter ? '' : $filter;
        $this->recordType = 'orders';
        $this->statusFilter = '';
        $this->resetPage();
    }

    /** One segment of the Orders group; '' is every order. */
    public function setStatusFilter($status)
    {
        $this->clearSelection();
        $this->statusFilter = in_array($status, self::ORDER_STATUSES, true) ? $status : '';
        $this->recordType = 'orders';
        $this->quickFilter  = '';
        $this->resetPage();
    }

    public function setRecordType(string $type): void
    {
        $this->clearSelection();
        $this->recordType = $type === 'refractions' ? 'refractions' : 'orders';
        $this->statusFilter = '';
        $this->quickFilter = '';
        $this->resetPage();
    }

    public function resetFilters()
    {
        $this->clearSelection();
        $this->searchTerm   = '';
        $this->fromDate     = now()->subMonths(3)->format('Y-m-d');
        $this->toDate       = now()->format('Y-m-d');
        $this->statusFilter = '';
        $this->quickFilter  = '';
        $this->labFilter    = '';
        $this->doctorFilter = '';
        $this->resetPage();
    }

    /* =================== SORTING =================== */

    public function sortBy($field)
    {
        if (! in_array($field, ['pickUpDate', 'created_at', 'updated_at'], true)) return;
        $this->sortDirection = ($this->sortField === $field && $this->sortDirection === 'asc') ? 'desc' : 'asc';
        $this->sortField     = $field;
        $this->resetPage();
    }

    public function selectRefraction($refractionId): void
    {
        $this->activeRefractionId = (int) $refractionId;
    }

    /* =================== BULK SELECTION =================== */

    /* =================== BULK ACTIONS =================== */

    public function togglePick(int $refractionId): void
    {
        $this->picked = in_array($refractionId, $this->picked, true)
            ? array_values(array_diff($this->picked, [$refractionId]))
            : [...$this->picked, $refractionId];
        $this->bulkSkipped = [];
    }

    /** The header box: tick (or untick) every row on this page. */
    public function togglePickPage(): void
    {
        $ids = $this->getFilteredQuery()->paginate(12)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->picked = array_diff($ids, $this->picked)
            ? array_values(array_unique([...$this->picked, ...$ids]))
            : array_values(array_diff($this->picked, $ids));
        $this->bulkSkipped = [];
    }

    /** Every row the filters match, across pages (up to 500 at a time). */
    public function pickAllMatching(): void
    {
        $this->picked = $this->getFilteredQuery()->limit(500)->pluck($this->getFilteredQuery()->qualifyColumn('id'))->map(fn ($id) => (int) $id)->all();
        $this->bulkSkipped = [];
    }

    public function clearSelection(): void
    {
        $this->picked = [];
        $this->bulkStatus = '';
        $this->bulkSendOpen = false;
    }

    /** Ticked refractions, with what the bulk actions check. */
    private function pickedRefractions()
    {
        return Refractions::with(['consultation.patient', 'consultation.cartItems.product.category', 'consultation.sale.items.product.category', 'lensOrder'])
            ->whereIn('id', $this->picked)->get();
    }

    public function bulkUpdateStatus()
    {
        $orders = $this->pickedRefractions()->pluck('lensOrder')->filter();
        if (!$this->bulkStatus || $orders->isEmpty()) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Tick orders and choose a status first.']);
            return;
        }

        // The same rules as one at a time: each order goes through updateStatus.
        $updated = 0;
        $skipped = [];
        $overrideReason = $this->statusOverrideReason;
        foreach ($orders as $order) {
            $old = $order->status;
            $this->statusOverrideReason = $overrideReason;
            $this->updateStatus($order->id, $this->bulkStatus);
            if ($order->fresh()->status !== $old) $updated++;
            elseif ($old !== $this->bulkStatus) $skipped[] = ['name' => $order->order_id, 'reason' => "still {$old}: this move isn't allowed from there"];
        }

        $this->statusOverrideReason = '';
        $this->clearSelection();
        $this->bulkSkipped = $skipped;
        $this->dispatch('notify', ...['type' => 'success', 'message' => "{$updated} order(s) updated".($skipped ? ', '.count($skipped).' not moved' : '').'.']);
    }

    /** Why a ticked refraction can't become an order in bulk, or null when it can. */
    private function bulkCreateBlocker(Refractions $refraction): ?string
    {
        if ($label = $refraction->activeOrderLabel()) return $label;
        if (!$refraction->dispensing_required) return 'not marked for glasses';
        if ($this->sentElsewhereMessage($refraction->id)) return 'sent to an optical centre';
        if (!$this->canCreateOrderFromPos($this->posOrderSummary($refraction))) return 'no payment at POS yet';
        $pos = $this->posDispensedProducts($refraction);
        // Bulk asks nothing per patient, so the frame and lens must both come from the POS sale.
        if (!$pos['lens'] || !$pos['frame']) return 'frame or lens not on the POS sale: create it on its own';
        return null;
    }

    /** "To order": an order for each ticked refraction whose frame and lens were sold at POS, all with one pickup date. */
    public function bulkCreateOrders(): void
    {
        $this->validate(['bulkPickUpDate' => 'required|date|after_or_equal:today'], [
            'bulkPickUpDate.required' => 'Choose the pickup date for these orders.',
            'bulkPickUpDate.after_or_equal' => 'The pickup date cannot be in the past.',
        ]);
        $created = 0;
        $skipped = [];
        $this->bulkRunning = true;
        foreach ($this->pickedRefractions() as $refraction) {
            $name = $refraction->consultation?->patient?->name ?? 'Refraction '.$refraction->id;
            if ($reason = $this->bulkCreateBlocker($refraction)) {
                $skipped[] = ['name' => $name, 'reason' => $reason];
                continue;
            }
            // createOrder makes it exactly as the dialog does (stock, audit); nothing extra is asked.
            $this->selectedRefractionId = $refraction->id;
            $this->pickUpDate = $this->bulkPickUpDate;
            $this->ownFrame = false;
            $this->selectedFrameId = $this->selectedLensId = null;
            $this->orderNotes = $this->labName = $this->labReference = $this->ownFrameDescription = '';
            $this->labCost = 0;
            try {
                $this->createOrder();
                LensOrder::where('refraction_id', $refraction->id)->exists() ? $created++ : $skipped[] = ['name' => $name, 'reason' => 'could not be created'];
            } catch (\Illuminate\Validation\ValidationException $e) {
                $skipped[] = ['name' => $name, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }
        $this->bulkRunning = false;
        $this->resetErrorBag();
        $this->clearSelection();
        $this->bulkSkipped = $skipped;
        $this->dispatch('notify', ...['type' => $created ? 'success' : 'error', 'message' => "{$created} order(s) created".($skipped ? ', '.count($skipped).' skipped' : '').'.']);
    }

    public function openBulkSend(): void
    {
        $targets = app(\App\Services\OpticalLinks\OpticalLinkService::class)->sendTargets();
        $this->bulkPartnerId = $targets->count() === 1 ? $targets->first()->id : null;
        $this->bulkFrameChoice = 'patient_chooses';
        $this->bulkUrgency = 'normal';
        $this->bulkNotes = '';
        $this->resetErrorBag();
        $this->bulkSendOpen = true;
    }

    /** "To order": send each ticked, paid refraction to one optical centre, with the same frame choice, urgency and notes. */
    public function bulkSend(): void
    {
        $this->validate([
            'bulkPartnerId' => 'required|integer',
            'bulkFrameChoice' => 'required|in:'.implode(',', array_keys(\App\Models\OpticalOrderRequest::FRAME_CHOICES)),
            'bulkUrgency' => 'required|in:normal,urgent',
            'bulkNotes' => 'nullable|string|max:2000',
        ], ['bulkPartnerId.required' => 'Choose the optical centre.']);

        $service = app(\App\Services\OpticalLinks\OpticalLinkService::class);
        $sent = 0;
        $skipped = [];
        foreach ($this->pickedRefractions() as $refraction) {
            $name = $refraction->consultation?->patient?->name ?? 'Refraction '.$refraction->id;
            if (!$refraction->dispensing_authorized_at) { $skipped[] = ['name' => $name, 'reason' => 'not authorised for dispensing']; continue; }
            if (!\App\Support\Optical\ClinicSpectacleBilling::paidEnough($this->posOrderSummary($refraction))) { $skipped[] = ['name' => $name, 'reason' => 'no payment at POS yet']; continue; }
            try {
                $service->send($refraction, (int) $this->bulkPartnerId, [
                    'frame_choice' => $this->bulkFrameChoice, 'frame_notes' => '', 'lens_notes' => '',
                    'urgency' => $this->bulkUrgency, 'notes' => $this->bulkNotes,
                ], auth()->user());
                $sent++;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $skipped[] = ['name' => $name, 'reason' => collect($e->errors())->flatten()->first()];
                // A credit limit or a centre that stopped taking orders stops the rest too.
                if (!$sent && str_contains(strtolower((string) collect($e->errors())->flatten()->first()), 'limit')) break;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $skipped[] = ['name' => $name, 'reason' => 'cannot be sent'];
            }
        }
        $this->clearSelection();
        $this->bulkSkipped = $skipped;
        $this->dispatch('notify', ...['type' => $sent ? 'success' : 'error', 'message' => "{$sent} sent to the optical centre".($skipped ? ', '.count($skipped).' skipped' : '').'.']);
    }

    /* =================== INLINE EDITING =================== */

    public function startEdit($orderId, $field)
    {
        if (! in_array($field, ['pickUpDate', 'frame_model_number', 'notes'], true)) return;
        $this->editingOrderId    = $orderId;
        $order                   = LensOrder::findOrFail($orderId);
        $this->editField[$field] = $order->{$field};
    }

    public function saveEdit($orderId, $field)
    {
        if (! in_array($field, ['pickUpDate', 'frame_model_number', 'notes'], true)) {
            $this->dispatch('notify', type: 'error', message: 'This spectacle order field cannot be edited inline.');
            return;
        }
        $rules = match ($field) {
            'pickUpDate' => ['nullable', 'date'],
            'frame_model_number' => ['nullable', 'string', 'max:255'],
            default => ['nullable', 'string', 'max:2000'],
        };
        $this->validate(["editField.{$field}" => $rules]);
        $order = LensOrder::findOrFail($orderId);
        $old   = $order->{$field};
        $order->update([$field => $this->editField[$field] ?? null]);
        $this->recordOrderAudit('spectacles.field_edited', $order, [$field => $old], [$field => $order->{$field}]);
        $this->editingOrderId = null;
        $this->editField      = [];
        // A new promised date is texted to the patient when the clinic has "Order Delay" switched on.
        $texted = $field === 'pickUpDate' && app(\App\Services\Messaging\FollowUpSms::class)->orderDateChanged($order, $old);
        $this->dispatch('notify', ...['type' => 'success', 'message' => ucfirst($field) . ' updated.' . ($texted ? ' The patient was texted the new date.' : '')]);
    }

    public function cancelEdit()
    {
        $this->editingOrderId = null;
        $this->editField      = [];
    }

    /* =================== STATUS MANAGEMENT =================== */

    public function updateOrderStatus($orderId, $newStatus)
    {
        $this->updateStatus($orderId, $newStatus);
    }

    public function updateStatus($orderId, $newStatus)
    {
        $order     = LensOrder::findOrFail($orderId);
        $oldStatus = $order->status;

        if (!in_array($newStatus, ['Pending', 'In Lab', 'Ready', 'Collected'], true)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Invalid spectacle order status.']);
            return;
        }
        if ($oldStatus === 'Cancelled') {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'A cancelled order is closed and cannot return to production.']);
            return;
        }

        if (!$this->canTransition($order, $newStatus)) return;

        // The glasses leave only once the bill is paid (as on the optical side).
        if ($newStatus === 'Collected' && $order->refraction) {
            $bill = $this->posOrderSummary($order->refraction);
            $owed = (float) ($bill['bill_balance'] ?? $bill['balance'] ?? 0);
            if ($owed > 0.004) {
                $this->dispatch('notify', ...['type' => 'error', 'message' => 'Balance ' . currency() . ' ' . number_format($owed, 2)
                    . ' is still due on the bill. Take it at the POS (Outstanding Balances) first.']);
                return;
            }
        }

        $noteMap = [
            'Collected' => 'Collected by ' . (Auth::user()->name ?? 'staff') . ' on ' . now()->format('d M Y H:i'),
            'Ready'     => 'Ready for pickup - ' . now()->format('d M Y H:i'),
            'In Lab'    => 'Sent to lab - ' . now()->format('d M Y H:i'),
        ];

        if (isset($noteMap[$newStatus])) {
            $this->appendOrderNote($order, $noteMap[$newStatus]);
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === 'Collected' && !$order->collected_at) {
            $updateData['collected_at']  = now();
            $updateData['renewal_date']  = now()->addYear()->toDateString();
        }

        $order->update($updateData);
        $this->statusOverrideReason = '';
        $this->recordOrderAudit('spectacles.status_changed', $order, ['status' => $oldStatus], ['status' => $newStatus]);
        $this->dispatch('notify', ...['type' => 'success', 'message' => "Order marked as {$newStatus}."]);

        if ($newStatus === 'Ready') {
            $order->load('refraction.consultation.patient');
            $patient = optional(optional($order->refraction)->consultation)->patient;
            $patientName = $patient?->name ?? 'Patient';

            \App\Services\NotificationService::sendToRoles(
                ['Secretary', 'Manager', 'Super Admin'],
                'spectacles_ready',
                'Spectacles Ready: ' . $patientName,
                'Order ' . $order->order_id . ' is ready for collection.',
                'fas fa-glasses',
                'text-info',
                route('secretary.spectacles'),
                ['order_id' => $order->id],
                Auth::id()
            );

            if ($patient?->contact) {
                $clinic = Setting::getSettings()->clinic_name ?? 'the clinic';
                $smsMsg = SmsTemplate::render('spectacles_ready', [
                    '[NAME]'     => $patient->name,
                    '[ORDER_ID]' => $order->order_id,
                    '[CLINIC]'   => $clinic,
                ]);
                if ($smsMsg) (new SmsService)->send($patient->contact, $smsMsg, $patient->id, 'spectacles_ready');
            }
        }

    }

    private function canTransition(LensOrder $order, string $newStatus): bool
    {
        $sequence = ['Pending', 'In Lab', 'Ready', 'Collected'];
        $position = array_search($order->status, $sequence, true);
        $expected = $position !== false ? ($sequence[$position + 1] ?? null) : null;
        $override = $newStatus !== $expected;
        $authorizedOverride = \App\Support\Access\Access::can('spectacles.override') && trim($this->statusOverrideReason) !== '';

        if ($override && !$authorizedOverride) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Next required action: ' . ($expected ?? 'No further action') . '. Manager override requires a reason.']);
            return false;
        }

        // Collection with money owed is refused in updateStatus(), with no override.

        if ($override) $this->appendOrderNote($order, 'Override reason: ' . trim($this->statusOverrideReason) . ' - ' . (Auth::user()->name ?? 'staff'));
        return true;
    }

    public function nextRequiredAction(?LensOrder $order): string
    {
        if (!$order) return 'Create spectacle order';
        return match ($order->status) {
            'Pending' => 'Send to lab', 'In Lab' => 'Mark ready', 'Ready' => 'Collect after balance check',
            'Collected' => 'Completed', 'Cancelled' => 'Cancelled', default => 'Review order',
        };
    }

    /* =================== ORDER CREATION =================== */

    public function openOrderModal($refractionId)
    {
        $refraction = Refractions::with([
            'consultation.cartItems.product.category',
            'consultation.sale.items.product.category',
        ])->findOrFail($refractionId);

        if (!$refraction->dispensing_required) {
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'This refraction was not marked for spectacle dispensing.',
            ]);
            return;
        }

        if (!$this->canCreateOrderFromPos($this->posOrderSummary($refraction))) {
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'Record a part or full payment at POS before creating a spectacle order.',
            ]);
            return;
        }

        if ($message = $this->sentElsewhereMessage($refraction->id)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => $message]);
            return;
        }

        if ($label = $refraction->activeOrderLabel()) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'This prescription '.$label.'.']);
            return;
        }

        $posProducts = $this->posDispensedProducts($refraction);

        $this->selectedRefractionId = $refractionId;
        $this->showOrderModal       = true;
        $this->pickUpDate           = now()->addDays(7)->format('Y-m-d');
        $this->selectedFrameId      = $posProducts['frame']?->id;
        $this->selectedLensId       = $posProducts['lens']?->id;
        // Lens paid at POS without a frame: the patient is bringing their own.
        $this->ownFrame             = $posProducts['lens'] && !$posProducts['frame'];
        $this->ownFrameDescription  = '';
        $this->frameSearchTerm      = '';
        $this->lensSearchTerm       = '';
        $this->framePrice           = 0;
        $this->lensPrice            = 0;
        $this->orderNotes           = '';
        $this->labName              = '';
        $this->labReference         = '';
        $this->labCost              = 0;
        $this->showFrameResults     = false;
        $this->showLensResults      = false;
        $this->resetErrorBag();
    }

    public function closeOrderModal()
    {
        $this->showOrderModal       = false;
        $this->selectedRefractionId = null;
        $this->resetErrorBag();
    }

    public function createOrder()
    {
        $clinicId = Product::clinicIdForWrite();
        $refraction = Refractions::with([
            'consultation.patient',
            'consultation.cartItems.product.category',
            'consultation.sale.items.product.category',
        ])->findOrFail($this->selectedRefractionId);
        $posProducts = $this->posDispensedProducts($refraction);

        // A frame or lens already sold at POS is fixed; only ask for what the sale lacks.
        // A patient's own frame needs no product, only an optional description.
        $frameNotNeeded = $posProducts['frame'] || $this->ownFrame;
        $this->validate([
            'pickUpDate'  => 'required|date|after_or_equal:today',
            'orderNotes'  => 'nullable|string|max:2000',
            'selectedFrameId' => $frameNotNeeded ? ['nullable'] : ['required', Rule::exists('products', 'id')->where('clinic_id', $clinicId)],
            'ownFrameDescription' => 'nullable|string|max:200',
            'selectedLensId' => $posProducts['lens'] ? ['nullable'] : ['required', Rule::exists('products', 'id')->where('clinic_id', $clinicId)],
            'labCost' => 'nullable|numeric|min:0',
        ]);

        if ($label = $refraction->activeOrderLabel()) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'This prescription '.$label.'.']);
            return;
        }

        // Sent to an optical centre while this dialog was open (or from another screen): that centre makes the glasses.
        if ($message = $this->sentElsewhereMessage($refraction->id)) {
            $this->closeOrderModal();
            $this->dispatch('notify', ...['type' => 'error', 'message' => $message]);
            return;
        }

        if (!$refraction->dispensing_required) {
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'This refraction was not marked for spectacle dispensing.',
            ]);
            return;
        }

        $posSummary = $this->posOrderSummary($refraction);
        if (!$this->canCreateOrderFromPos($posSummary)) {
            $this->dispatch('notify', ...[
                'type' => 'error',
                'message' => 'Record a part or full payment at POS before creating a spectacle order.',
            ]);
            return;
        }

        $orderId = 'ORD-' . strtoupper(Str::random(8));

        [$order, $refraction] = \DB::transaction(function () use ($orderId) {
            // Lock the prescription first so it cannot change while the order
            // and its reserved stock are being created.
            $refraction = Refractions::with([
                'consultation.patient',
                'consultation.cartItems.product.category',
                'consultation.sale.items.product.category',
            ])->lockForUpdate()->findOrFail($this->selectedRefractionId);

            if ($refraction->hasActiveLensOrder()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'selectedRefractionId' => 'An order already exists for this refraction.',
                ]);
            }
            if (!$refraction->dispensing_required) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'selectedRefractionId' => 'This refraction is not authorized for spectacle dispensing.',
                ]);
            }
            if (!$this->canCreateOrderFromPos($this->posOrderSummary($refraction))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'selectedRefractionId' => 'Record a part or full payment at POS before creating a spectacle order.',
                ]);
            }

            // POS checkout already deducted stock for the frame/lens it sold,
            // so those are reused as-is and only the rest is deducted here.
            $posProducts = $this->posDispensedProducts($refraction);
            $frameFromSale = (bool) $posProducts['frame'];
            $lensFromSale = (bool) $posProducts['lens'];
            // A frame bought at POS always wins over the own-frame option.
            $ownFrame = !$frameFromSale && (bool) $this->ownFrame;
            $deductFrame = !$frameFromSale && !$ownFrame;

            $frame = $ownFrame ? null : ($posProducts['frame'] ?? Product::with('category')->lockForUpdate()->findOrFail($this->selectedFrameId));
            $lens = $posProducts['lens'] ?? Product::with('category')->lockForUpdate()->findOrFail($this->selectedLensId);
            if ($deductFrame && !Str::contains(strtolower((string) optional($frame->category)->name), 'frame')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['selectedFrameId' => 'Select a product from the frame category.']);
            }
            if (!$lensFromSale && !Str::contains(strtolower((string) optional($lens->category)->name), 'lens')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['selectedLensId' => 'Select a product from the lens category.']);
            }
            $requiredFrame = $deductFrame && !$lensFromSale && $frame->id === $lens->id ? 2 : 1;
            if (($deductFrame && !$frame->canSupply($requiredFrame)) || (!$lensFromSale && !$lens->canSupply())) {
                throw \Illuminate\Validation\ValidationException::withMessages(['selectedFrameId' => 'Selected frame or lens is no longer in stock.']);
            }
            $inventory = app(\App\Services\Inventory\BranchInventoryService::class);
            if ($deductFrame)   $inventory->decrease($frame, 1);
            if (!$lensFromSale) $inventory->decrease($lens, 1);
            $ownFrameDescription = trim((string) $this->ownFrameDescription);
            $frameName = $ownFrame
                ? "Patient's own frame" . ($ownFrameDescription !== '' ? " - {$ownFrameDescription}" : '')
                : $frame->name;
            $notes = trim(collect([
                $this->orderNotes,
                $this->labName ? "[Lab: {$this->labName}]" : null,
                $this->labReference ? "[Lab Ref: {$this->labReference}]" : null,
            ])->filter()->implode("\n"));
            $order = LensOrder::create([
                'user_id' => Auth::id(), 'refraction_id' => $this->selectedRefractionId, 'order_id' => $orderId,
                'frame_model_number' => $frameName, 'frame_product_id' => $frame?->id, 'own_frame' => $ownFrame,
                'lens_product_id' => $lens->id,
                'frame_price' => $ownFrame ? 0 : ($posProducts['frame_price'] ?? $frame->selling_price),
                'lens_price' => $posProducts['lens_price'] ?? $lens->selling_price, 'lab_cost' => $this->labCost ?: 0,
                'stock_reserved_at' => $deductFrame || !$lensFromSale ? now() : null,
                'frame_stock_from_sale' => $frameFromSale, 'lens_stock_from_sale' => $lensFromSale,
                'status' => 'Pending', 'pickUpDate' => $this->pickUpDate, 'notes' => $notes,
            ]);

            return [$order, $refraction];
        });

        $this->recordOrderAudit('spectacles.created', $order, [], [
            'order_id'    => $order->order_id,
            'pickUpDate'  => $order->pickUpDate,
        ]);

        $this->closeOrderModal();
        if (!$this->bulkRunning) $this->dispatch('notify', ...['type' => 'success', 'message' => "Order {$orderId} created for {$refraction->consultation->patient->name}!"]);
    }

    /* =================== CANCEL ORDER =================== */

    public function openCancelConfirm($orderId)
    {
        $this->cancelConfirmId = $orderId;
        $this->cancelReason    = '';
    }

    public function closeCancelConfirm()
    {
        $this->cancelConfirmId = null;
        $this->cancelReason    = '';
    }

    public function confirmCancelOrder()
    {
        if (!$this->cancelConfirmId) return;

        $this->validate([
            'cancelReason' => 'required|string|max:500',
        ], [
            'cancelReason.required' => 'Enter a reason for cancelling this order.',
        ]);

        $cancelConfirmId = $this->cancelConfirmId;
        $cancelReason    = trim($this->cancelReason);

        \DB::transaction(function () use ($cancelConfirmId, $cancelReason) {
            $order = LensOrder::lockForUpdate()->findOrFail($cancelConfirmId);
            if ($order->status === 'Cancelled') return;
            if ($order->status === 'Collected') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'cancelReason' => 'A collected order cannot be cancelled or returned to stock.',
                ]);
            }
            $order->update(['status' => 'Cancelled', 'cancelled_at' => now()]);
            $this->appendOrderNote($order, 'Cancelled - ' . $cancelReason . ' - ' . (Auth::user()->name ?? 'staff'));

            // Items sold at POS are returned to stock by a POS refund, not here.
            if ($order->stock_reserved_at) {
                $inventory = app(\App\Services\Inventory\BranchInventoryService::class);
                if ($order->frame_product_id && !$order->frame_stock_from_sale) $inventory->increase((int) $order->frame_product_id, 1);
                if ($order->lens_product_id && !$order->lens_stock_from_sale)   $inventory->increase((int) $order->lens_product_id, 1);
                $order->update(['stock_reserved_at' => null]);
            }

            $this->recordOrderAudit('spectacles.cancelled', $order, [], ['reason' => $cancelReason]);
        });

        $this->closeCancelConfirm();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Order cancelled and stock restored.']);
    }

    /* =================== PRINT PREVIEW =================== */

    public function openPrintPreview($orderId)
    {
        $this->printPreviewOrderId = $orderId;
        $this->showPrintPreview    = true;
        $this->autoPrint           = false;
    }

    public function directPrint($orderId)
    {
        $this->printPreviewOrderId = $orderId;
        $this->showPrintPreview    = true;
        $this->autoPrint           = true;
        $this->dispatch('auto-print-job-card');
    }

    public function closePrintPreview()
    {
        $this->showPrintPreview    = false;
        $this->printPreviewOrderId = null;
        $this->autoPrint           = false;
    }

    public function downloadJobCard($orderId)
    {
        $order      = LensOrder::with([
            'refraction.consultation.patient',
            'refraction.consultation.sale.items.product.category',
            'frameProduct',
            'lensProduct',
            'user',
        ])->findOrFail($orderId);
        $appSettings = Setting::getSettings();
        $pdf        = Pdf::loadView('pdf.job-card-thermal', compact('order', 'appSettings'));
        return response()->streamDownload(fn() => print($pdf->output()), "JobCard_{$order->order_id}.pdf");
    }

    /* =================== PRODUCT SEARCH =================== */

    public function updatedFrameSearchTerm()
    {
        $this->showFrameResults = strlen($this->frameSearchTerm) >= 2;
        $this->selectedFrameId  = null;
        $this->framePrice       = 0;
    }

    public function updatedLensSearchTerm()
    {
        $this->showLensResults = strlen($this->lensSearchTerm) >= 2;
        $this->selectedLensId  = null;
        $this->lensPrice       = 0;
    }

    public function selectFrameById($productId)
    {
        $product = Product::find($productId);
        if (!$product) return;
        $this->selectedFrameId  = $product->id;
        $this->frameSearchTerm  = $product->name;
        $this->framePrice       = $product->selling_price;
        $this->showFrameResults = false;
    }

    public function selectLensById($productId)
    {
        $product = Product::find($productId);
        if (!$product) return;
        $this->selectedLensId  = $product->id;
        $this->lensSearchTerm  = $product->name;
        $this->lensPrice       = $product->selling_price;
        $this->showLensResults = false;
    }

    /* =================== REMINDERS =================== */

    public function sendReadyReminder($orderId): void
    {
        $order   = LensOrder::with('refraction.consultation.patient')->findOrFail($orderId);
        $patient = optional(optional($order->refraction)->consultation)->patient;
        $contact = $patient?->contact ?? '';

        $this->appendOrderNote($order, "Ready-pickup reminder sent to " . ($contact ?: 'no contact') . " – " . now()->format('d M Y H:i'));

        if ($contact) {
            $clinic  = Setting::getSettings()->clinic_name ?? 'the clinic';
            $smsMsg  = SmsTemplate::render('spectacles_reminder', [
                '[NAME]'     => $patient->name,
                '[ORDER_ID]' => $order->order_id,
                '[CLINIC]'   => $clinic,
            ]) ?: "Hello {$patient->name}, your spectacles (Order {$order->order_id}) are still waiting for collection at {$clinic}.";
            $result  = (new SmsService)->send($contact, $smsMsg, $patient->id, 'spectacles_reminder');
            $type = $result['success'] ? 'success' : 'warning';
            $msg  = $result['success']
                ? 'Reminder SMS sent successfully.'
                : 'Reminder logged (SMS failed: ' . ($result['error'] ?? 'unknown') . ')';
        } else {
            $type = 'info';
            $msg  = 'Reminder logged — no contact number on record.';
        }

        $this->dispatch('notify', ...['type' => $type, 'message' => $msg]);
    }

    /* =================== RENEWAL DATE =================== */

    public function openRenewalEdit($orderId): void
    {
        $order                   = LensOrder::findOrFail($orderId);
        $this->renewalEditOrderId = $orderId;
        $this->renewalEditDate    = $order->renewal_date?->format('Y-m-d') ?? '';
    }

    public function saveRenewalDate(): void
    {
        $this->validate(['renewalEditDate' => 'required|date']);

        $order = LensOrder::findOrFail($this->renewalEditOrderId);
        $old   = $order->renewal_date?->toDateString();
        $order->update([
            'renewal_date'             => $this->renewalEditDate,
            'renewal_reminder_sent_at' => null,
        ]);
        $this->recordOrderAudit('spectacles.renewal_date_updated', $order, ['renewal_date' => $old], ['renewal_date' => $this->renewalEditDate]);
        $this->renewalEditOrderId = null;
        $this->renewalEditDate    = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'Renewal date updated.']);
    }

    public function cancelRenewalEdit(): void
    {
        $this->renewalEditOrderId = null;
        $this->renewalEditDate    = '';
    }

    /* =================== EXPORT =================== */

    public function exportCSV()
    {
        $filename = 'spectacles_' . date('Y-m-d_His') . '.csv';
        $data     = $this->getFilteredQuery()->get();

        return response()->streamDownload(function () use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Order ID', 'Patient', 'Doctor', 'Frame', 'Lens Type', 'Status', 'Pickup Date', 'Lab', 'Created']);
            foreach ($data as $row) {
                $order = $row->lensOrder;
                fputcsv($file, [
                    $order?->order_id ?? '',
                    optional(optional($row->consultation)->patient)->name ?? '',
                    optional(optional($row->consultation)->doctor)->name ?? '',
                    $order?->frame_model_number ?? '',
                    $row->lensType ?? '',
                    $order?->status ?? 'Pending (no order)',
                    $order?->pickUpDate ?? '',
                    $order ? $this->extractNoteValue($order, 'Lab') : '',
                    $order?->created_at?->format('Y-m-d H:i') ?? '',
                ]);
            }
            fclose($file);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /* =================== QUERY BUILDER =================== */

    private function getFilteredQuery()
    {
        $query = Refractions::query()
            ->with([
                'consultation.patient',
                'consultation.doctor',
                'consultation.cartItems.product.category',
                'consultation.sale.items.product.category',
                'lensOrder.frameProduct',
                'lensOrder.lensProduct',
                'dispensingAuthorizedBy',
            ])
            ->when($this->searchTerm, function ($q) {
                $term = $this->searchTerm;
                $q->where(function ($inner) use ($term) {
                    $inner->whereHas('consultation.patient', fn($p) =>
                        $p->where('name', 'like', "%{$term}%")
                    )
                    ->orWhereHas('lensOrder', fn($l) =>
                        $l->where('frame_model_number', 'like', "%{$term}%")
                          ->orWhere('order_id', 'like', "%{$term}%")
                    );
                });
            })
            ->when($this->doctorFilter, fn($q) =>
                $q->whereHas('consultation', fn($c) => $c->where('user_id', $this->doctorFilter))
            );

        $this->recordType === 'orders'
            ? $query->whereHas('lensOrder')
            : $query->awaitingSpectacleOrder()->whereNotIn($query->qualifyColumn('id'), self::acceptedAtOptical());

        // statusFilter is an order's own status; refractions with no order are the "To order" list (recordType).
        if ($this->recordType === 'orders' && in_array($this->statusFilter, self::ORDER_STATUSES, true)) {
            $query->whereHas('lensOrder', fn($l) => $l->where('status', $this->statusFilter));
        }

        if ($this->labFilter) {
            $query->whereHas('lensOrder', fn($q) =>
                $q->where('notes', 'like', "%[Lab: {$this->labFilter}%")
            );
        }

        if ($this->quickFilter) {
            $query = $this->applyQuickFilter($query);
        }

        if ($this->fromDate || $this->toDate) {
            $query->where(function ($q) {
                $q->whereHas('lensOrder', function ($l) {
                    if ($this->fromDate) $l->whereDateIndexed('created_at', '>=', $this->fromDate);
                    if ($this->toDate)   $l->whereDateIndexed('created_at', '<=', $this->toDate);
                })
                ->orDoesntHave('lensOrder');
            });
        }

        if ($this->sortField === 'pickUpDate') {
            $query->orderByRaw(
                '(SELECT pickUpDate FROM lens_orders WHERE lens_orders.refraction_id = refractions.id AND lens_orders.deleted_at IS NULL) ' . $this->sortDirection
            );
        } elseif (in_array($this->sortField, ['created_at', 'updated_at'])) {
            $query->orderBy($this->sortField, $this->sortDirection);
        }

        return $query;
    }

    private function applyQuickFilter($query)
    {
        return match ($this->quickFilter) {
            'today'          => $query->whereHas('lensOrder', fn($q) => $q->whereDateIndexed('created_at', today())),
            'week'           => $query->whereHas('lensOrder', fn($q) => $q->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])),
            'overdue'        => $query->whereHas('lensOrder', fn($q) => $q->where('status', 'Ready')->whereDateIndexed('updated_at', '<=', now()->subDays(7))),
            'ready'          => $query->whereHas('lensOrder', fn($q) => $q->where('status', 'Ready')),
            'renewal_due'    => $query->whereHas('lensOrder', fn($q) =>
                                    $q->where('status', 'Collected')
                                      ->whereNotNull('renewal_date')
                                      ->whereDateIndexed('renewal_date', '<=', now()->addDays(30))
                                      ->whereNull('renewal_reminder_sent_at')
                                 ),
            default          => $query,
        };
    }

    /* =================== HELPERS =================== */

    public function orderNoteLines($notes): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $notes))
            ->map(fn($line) => trim($line))
            ->filter()
            ->values()
            ->toArray();
    }

    public function extractNoteValue(LensOrder $order, string $key): string
    {
        foreach ($this->orderNoteLines($order->notes) as $line) {
            if (Str::startsWith($line, "[{$key}:")) {
                return trim(Str::between($line, "[{$key}:", ']'));
            }
        }
        return '';
    }

    public function posOrderSummary($refraction): array
    {
        return \App\Support\Optical\ClinicSpectacleBilling::summary($refraction);
    }

    /**
     * Frame and lens the patient bought on the POS sale, with the sold prices.
     * POS checkout deducts their stock, so an order reuses them rather than
     * asking staff to pick (and deduct) them again. Refunded items are ignored.
     */
    public function posDispensedProducts($refraction): array
    {
        $items = collect($this->posOrderSummary($refraction)['items'] ?? [])->filter(function ($item) {
            $refunded = (int) ($item->refunded_quantity ?? 0);
            return $item->product && !($refunded > 0 && $refunded >= (int) ($item->dispensed_quantity ?? 0));
        });
        $category = fn ($item) => strtolower((string) optional($item->product->category)->name);
        $frames = $items->filter(fn ($item) => Str::contains($category($item), 'frame'));
        $lenses = $items->reject(fn ($item) => Str::contains($category($item), 'frame'))
            ->filter(fn ($item) => Str::contains($category($item), 'lens'));
        // Sale lines held on a part-paid bill have no subtotal yet: value them at their price.
        $price = fn ($group) => $group->isEmpty() ? null : (float) $group->sum(fn ($item) => $item->getAttribute('subtotal') !== null
            ? \App\Support\Optical\ClinicSpectacleBilling::lineValue($item) : (float) $item->total);

        return [
            'frame'       => $frames->first()?->product,
            'lens'        => $lenses->first()?->product,
            'frame_price' => $price($frames),
            'lens_price'  => $price($lenses),
        ];
    }

    /** A refraction sent to an optical centre (still open there) is that centre's job: no order is made here too. */
    private function sentElsewhereMessage(int $refractionId): ?string
    {
        // Linking optical centres needs a clinic (hosted); without one nothing can have been sent.
        if (!app(\App\Support\Tenancy\TenantContext::class)->clinicId()) return null;
        $sent = app(\App\Services\OpticalLinks\OpticalLinkService::class)->openRequestFor($refractionId);
        return $sent ? 'This prescription was sent to '.($sent->opticalClinic?->name ?? 'an optical centre').'. Follow it in Sent to Optical, or cancel it there before ordering here.' : null;
    }

    public function canCreateOrderFromPos(array $posSummary): bool
    {
        return \App\Support\Optical\ClinicSpectacleBilling::paidEnough($posSummary);
    }

    public function dispensingIssues(Refractions $refraction): array
    {
        $issues = [];
        if (!$refraction->refractionOD && !$refraction->refractionOS) $issues[] = 'Missing prescription';
        if (!$refraction->pd) $issues[] = 'Missing PD';
        if (!$refraction->lensType) $issues[] = 'Missing lens type';
        return $issues;
    }

    public function estimatedOrderProfit(LensOrder $order): float
    {
        $revenue = (float) $order->frame_price + (float) $order->lens_price;
        $cost = (float) ($order->frameProduct?->cost_price ?? 0)
            + (float) ($order->lensProduct?->cost_price ?? 0)
            + (float) $order->lab_cost;
        return $revenue - $cost;
    }

    /** Whether the clinic has more than one active branch. */
    private function hasBranches(): bool
    {
        $clinicId = app(\App\Support\Tenancy\TenantContext::class)->clinicId();

        return $clinicId && \App\Models\Branch::withoutGlobalScopes()->where('clinic_id', $clinicId)->where('is_active', true)->count() > 1;
    }

    /** Front-desk orders ready at another branch, found by patient name, number, phone or order number (3+ characters). */
    private function readyElsewhere()
    {
        $term = trim($this->otherBranchSearch);
        if (mb_strlen($term) < 3) return collect();
        $like = '%'.$term.'%';

        return LensOrder::withoutGlobalScope('branch')->with(['branch:id,name', 'refraction.consultation.patient'])
            ->whereNotNull('refraction_id')->where('status', 'Ready')
            ->where('branch_id', '!=', app(\App\Support\Tenancy\TenantContext::class)->branchId())
            ->where(fn ($q) => $q->where('order_id', 'like', $like)
                ->orWhereHas('refraction', fn ($r) => $r->withoutGlobalScope('branch')->whereHas('consultation', fn ($c) => $c->withoutGlobalScope('branch')
                    ->whereHas('patient', fn ($p) => $p->where('name', 'like', $like)->orWhere('pxnumber', 'like', $like)->orWhere('contact', 'like', $like)))))
            ->latest('id')->limit(10)->get();
    }

    /** Hand over glasses made for another branch: the bill must be paid (at any branch), as for a collection there. */
    public function collectHere(int $orderId): void
    {
        abort_unless(\App\Support\Access\Access::can('spectacles.manage'), 403);
        app(\App\Services\ClinicAccessService::class)->assertWritable();
        $this->resetErrorBag('otherBranchSearch');
        $here = app(\App\Support\Tenancy\TenantContext::class)->branch();
        $order = LensOrder::withoutGlobalScope('branch')->whereNotNull('refraction_id')->where('status', 'Ready')
            ->where('branch_id', '!=', $here?->id)->findOrFail($orderId);
        $bill = $order->refraction ? $this->posOrderSummary($order->refraction) : [];
        $owed = (float) ($bill['bill_balance'] ?? $bill['balance'] ?? 0);
        if ($owed > 0.004) {
            $this->addError('otherBranchSearch', 'Balance '.currency().' '.number_format($owed, 2).' is still due on the bill for order '.$order->order_id
                .'. Take it under Outstanding Balances (All branches) first.');
            return;
        }
        $from = \App\Models\Branch::withoutGlobalScopes()->whereKey($order->branch_id)->value('name');
        $this->appendOrderNote($order, 'Collected at '.($here?->name ?? 'another branch').' by '.(Auth::user()->name ?? 'staff').' on '.now()->format('d M Y H:i'));
        $order->update(['status' => 'Collected', 'collected_at' => $order->collected_at ?? now(), 'renewal_date' => $order->renewal_date ?? now()->addYear()->toDateString()]);
        $this->recordOrderAudit('spectacles.collected_other_branch', $order, ['status' => 'Ready', 'branch' => $from], ['status' => 'Collected', 'collected_at_branch' => $here?->name]);
        $this->otherBranchSearch = '';
        $this->dispatch('notify', ...['type' => 'success', 'message' => "Order {$order->order_id} from {$from} handed over here."]);
    }

    private function appendOrderNote(LensOrder $order, string $note): void
    {
        $order->notes = trim((string) $order->notes . "\n[" . $note . "]");
        $order->save();
    }

    private function recordOrderAudit(string $event, LensOrder $order, array $old = [], array $new = []): void
    {
        $order->loadMissing('refraction.consultation.patient');
        $patientId = optional(optional(optional($order->refraction)->consultation)->patient)->id;
        AuditTrail::record($event, $event . ' - ' . $order->order_id, $order, $old, $new, $patientId);
    }

    /* =================== RENDER =================== */

    /** The send dialog finished: redraw so the row shows "Sent". */
    #[\Livewire\Attributes\On('optical-request-sent')]
    public function refreshAfterSend(): void
    {
    }

    /** Refractions a linked optical centre has taken on: they leave the "needs order" queue (see Sent to Optical). */
    private static function acceptedAtOptical()
    {
        return \App\Models\OpticalOrderRequest::forSender((int) app(\App\Support\Tenancy\TenantContext::class)->clinicId())
            ->where('status', 'accepted')->whereNull('order_cancelled_at')->whereNotNull('refraction_id')->select('refraction_id');
    }

    public function render()
    {
        // Single grouped query instead of 5 individual count queries
        // Front-desk orders only (made from a refraction), as the list shows: an account with the Optical Suite
        // also holds the shop's own orders, which live on its Orders page.
        $statusCounts = LensOrder::whereNotNull('refraction_id')->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $stats = [
            'pending'     => Refractions::awaitingSpectacleOrder()->whereNotIn('id', self::acceptedAtOptical())->count(),
            'ordered'     => $statusCounts->get('Pending', 0),
            'in_lab'      => $statusCounts->get('In Lab', 0),
            'ready'       => $statusCounts->get('Ready', 0),
            'collected'   => $statusCounts->get('Collected', 0),
            'cancelled'   => $statusCounts->get('Cancelled', 0),
            'orders'      => (int) $statusCounts->sum(),
            'overdue'     => LensOrder::whereNotNull('refraction_id')->where('status', 'Ready')->where('updated_at', '<=', now()->subDays(7))->count(),
            'renewal_due' => LensOrder::whereNotNull('refraction_id')->where('status', 'Collected')
                                ->whereNotNull('renewal_date')
                                ->whereDateIndexed('renewal_date', '<=', now()->addDays(30))
                                ->whereNull('renewal_reminder_sent_at')
                                ->count(),
        ];

        $spectacles = $this->getFilteredQuery()->paginate(12);
        $hasBranches = $this->statusFilter === 'Ready' && $this->hasBranches();
        // The case drawer opens only for a row someone clicked (and that is still in the list).
        $activeRefraction = $this->activeRefractionId ? $spectacles->firstWhere('id', $this->activeRefractionId) : null;

        $frameSearchResults = [];
        $lensSearchResults  = [];

        if ($this->showFrameResults && strlen($this->frameSearchTerm) >= 2) {
            $frameSearchResults = Product::whereHas('category', fn($q) => $q->where('name', 'LIKE', '%frame%'))
                ->inStock()
                ->where(fn($q) =>
                    $q->where('name', 'LIKE', "%{$this->frameSearchTerm}%")
                      ->orWhere('batch_number', 'LIKE', "%{$this->frameSearchTerm}%")
                )
                ->limit(10)->get();
        }

        if ($this->showLensResults && strlen($this->lensSearchTerm) >= 2) {
            $lensSearchResults = Product::whereHas('category', fn($q) => $q->where('name', 'LIKE', '%lens%'))
                ->inStock()
                ->where(fn($q) =>
                    $q->where('name', 'LIKE', "%{$this->lensSearchTerm}%")
                      ->orWhere('batch_number', 'LIKE', "%{$this->lensSearchTerm}%")
                )
                ->limit(10)->get();
        }

        $orderPosProducts = ['frame' => null, 'lens' => null];
        if ($this->showOrderModal && $this->selectedRefractionId) {
            $orderRefraction = Refractions::with([
                'consultation.cartItems.product.category',
                'consultation.sale.items.product.category',
            ])->find($this->selectedRefractionId);
            if ($orderRefraction) $orderPosProducts = $this->posDispensedProducts($orderRefraction);
        }

        $printOrder = null;
        if ($this->showPrintPreview && $this->printPreviewOrderId) {
            $printOrder = LensOrder::with([
                'refraction.consultation.patient',
                'refraction.consultation.sale.items.product.category',
                'frameProduct',
                'user',
            ])->find($this->printPreviewOrderId);
        }

        $links = app(\App\Services\OpticalLinks\OpticalLinkService::class);
        $sentRequests = \App\Models\OpticalOrderRequest::forSender((int) app(\App\Support\Tenancy\TenantContext::class)->clinicId())
            ->where('kind', 'order')->whereIn('refraction_id', $spectacles->pluck('id'))->with('opticalClinic:id,name')->orderBy('id')->get()->keyBy('refraction_id');

        return view('livewire.secretary.spectacle-component', [
            'opticalTargets'     => $links->sendTargets()->count(),
            'bulkTargets'        => $this->bulkSendOpen ? $links->sendTargets() : collect(),
            // The optical shop's order page opens only on plans with Optical and for roles it covers; clinic plans start orders from a refraction here.
            'canOpenOpticalOrders' => app(\App\Services\ClinicAccessService::class)->access('optical')['allowed']
                && \App\Support\OpticalAccess::can(auth()->user(), 'optical.orders.create'),
            'sentRequests'       => $sentRequests,
            'spectacles'         => $spectacles,
            'showOtherBranches'  => $hasBranches,
            'clinicHasBranches'  => $hasBranches || $this->hasBranches(),
            'readyElsewhere'     => $hasBranches ? $this->readyElsewhere() : collect(),
            'activeRefraction'   => $activeRefraction,
            'stats'              => $stats,
            'frameSearchResults' => $frameSearchResults,
            'lensSearchResults'  => $lensSearchResults,
            'printOrder'         => $printOrder,
            'orderPosProducts'   => $orderPosProducts,
            'appSettings'        => Setting::getSettings(),
            'doctors'            => User::whereIn('id', \App\Models\Consultations::whereNotNull('user_id')->select('user_id'))->orderBy('name')->get(['id', 'name']),
            'labs'               => LensOrder::whereNotNull('notes')->get()->map(fn($o) => $this->extractNoteValue($o, 'Lab'))->filter()->unique()->values(),
            'availableFrames'    => Product::whereHas('category', fn($q) => $q->where('name', 'like', '%frame%'))->inStock()->orderBy('name')->get(['id','name','quantity','made_to_order','selling_price']),
            'availableLenses'    => Product::whereHas('category', fn($q) => $q->where('name', 'like', '%lens%'))->inStock()->orderBy('name')->get(['id','name','quantity','made_to_order','selling_price']),
        ])->layout('layouts.clinic', ['menu' => 'reception']);
    }
}
