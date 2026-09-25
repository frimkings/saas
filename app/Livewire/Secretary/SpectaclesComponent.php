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
use App\Services\EmailService;
use App\Models\SmsTemplate;
use App\Mail\SpectaclesReadyMail;

class SpectaclesComponent extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // Filters
    public $searchTerm   = '';
    public $fromDate;
    public $toDate;
    public $statusFilter = '';
    public $quickFilter  = '';
    public $labFilter    = '';
    public $doctorFilter = '';
    public $sortField      = 'created_at';
    public $sortDirection  = 'desc';
    public $activeRefractionId = null;
    public $recordType = 'orders';

    // Bulk selection
    public $selectedOrders = [];
    public $selectAllOrders = false;
    public $bulkStatus = '';

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
    }

    /* =================== FILTERS =================== */

    public function updatedSearchTerm()  { $this->resetPage(); }
    public function updatedStatusFilter(){ $this->resetPage(); }
    public function updatedDoctorFilter(){ $this->resetPage(); }
    public function updatedLabFilter()   { $this->resetPage(); }

    public function setQuickFilter($filter)
    {
        $this->quickFilter = $this->quickFilter === $filter ? '' : $filter;
        $this->resetPage();
    }

    public function setStatusFilter($status)
    {
        $this->statusFilter = $status;
        $this->recordType = $status === 'Pending' ? 'refractions' : 'orders';
        $this->quickFilter  = '';
        $this->resetPage();
    }

    public function setRecordType(string $type): void
    {
        $this->recordType = $type === 'refractions' ? 'refractions' : 'orders';
        $this->statusFilter = '';
        $this->quickFilter = '';
        $this->resetPage();
    }

    public function resetFilters()
    {
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

    public function toggleOrderSelection($orderId)
    {
        $orderId              = (int) $orderId;
        $this->selectedOrders = in_array($orderId, $this->selectedOrders)
            ? array_values(array_diff($this->selectedOrders, [$orderId]))
            : array_values(array_unique([...$this->selectedOrders, $orderId]));
        $this->selectAllOrders = false;
    }

    public function updatedSelectAllOrders()
    {
        if ($this->selectAllOrders) {
            $this->selectedOrders = $this->getFilteredQuery()
                ->whereHas('lensOrder')
                ->with('lensOrder:id,refraction_id')
                ->get()
                ->map(fn($r) => $r->lensOrder?->id)
                ->filter()
                ->values()
                ->toArray();
        } else {
            $this->selectedOrders = [];
        }
    }

    public function clearSelection()
    {
        $this->selectedOrders  = [];
        $this->selectAllOrders = false;
    }

    public function bulkUpdateStatus()
    {
        if (!$this->bulkStatus || empty($this->selectedOrders)) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Select orders and a status first.']);
            return;
        }

        $updated = 0;
        $overrideReason = $this->statusOverrideReason;
        foreach (LensOrder::whereIn('id', $this->selectedOrders)->get() as $order) {
            $old = $order->status;
            $this->statusOverrideReason = $overrideReason;
            $this->updateStatus($order->id, $this->bulkStatus);
            if ($order->fresh()->status !== $old) $updated++;
        }

        $this->bulkStatus = '';
        $this->statusOverrideReason = '';
        $this->clearSelection();
        $this->dispatch('notify', ...['type' => 'success', 'message' => "{$updated} order(s) updated."]);
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
        $this->dispatch('notify', ...['type' => 'success', 'message' => ucfirst($field) . ' updated.']);
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
            if ($patient?->email) {
                $clinic = Setting::getSettings()->clinic_name ?? 'the clinic';
                (new EmailService)->send($patient->email, new SpectaclesReadyMail(
                    $patient->name, $clinic, $order->order_id
                ));
            }
        }

    }

    private function canTransition(LensOrder $order, string $newStatus): bool
    {
        $sequence = ['Pending', 'In Lab', 'Ready', 'Collected'];
        $position = array_search($order->status, $sequence, true);
        $expected = $position !== false ? ($sequence[$position + 1] ?? null) : null;
        $override = $newStatus !== $expected;
        $authorizedOverride = Auth::user()?->hasAnyRole(['Manager', 'Super Admin']) && trim($this->statusOverrideReason) !== '';

        if ($override && !$authorizedOverride) {
            $this->dispatch('notify', ...['type' => 'warning', 'message' => 'Next required action: ' . ($expected ?? 'No further action') . '. Manager override requires a reason.']);
            return false;
        }

        if ($newStatus === 'Collected') {
            $order->loadMissing('refraction.consultation.sale.items.product.category', 'refraction.consultation.cartItems.product.category');
            if (($this->posOrderSummary($order->refraction)['balance'] ?? 0) > 0 && !$authorizedOverride) {
                $this->dispatch('notify', ...['type' => 'error', 'message' => 'Collection blocked: outstanding balance remains.']);
                return false;
            }
        }

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

        if (LensOrder::withTrashed()->where('refraction_id', $refraction->id)->exists()) {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'An order already exists for this refraction.']);
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

            if (LensOrder::withTrashed()->where('refraction_id', $refraction->id)->exists()) {
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
            if (($deductFrame && $frame->quantity < $requiredFrame) || (!$lensFromSale && $lens->quantity < 1)) {
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
        $this->dispatch('notify', ...['type' => 'success', 'message' => "Order {$orderId} created for {$refraction->consultation->patient->name}!"]);
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
            : $query->where('dispensing_required', true)->doesntHave('lensOrder');

        if ($this->statusFilter) {
            if ($this->statusFilter === 'Pending') {
                $query->where('dispensing_required', true)->doesntHave('lensOrder');
            } elseif ($this->statusFilter === 'Ordered') {
                $query->whereHas('lensOrder', fn($l) => $l->where('status', 'Pending'));
            } else {
                $query->whereHas('lensOrder', fn($l) => $l->where('status', $this->statusFilter));
            }
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
                    if ($this->fromDate) $l->whereDate('created_at', '>=', $this->fromDate);
                    if ($this->toDate)   $l->whereDate('created_at', '<=', $this->toDate);
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
            'today'          => $query->whereHas('lensOrder', fn($q) => $q->whereDate('created_at', today())),
            'week'           => $query->whereHas('lensOrder', fn($q) => $q->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])),
            'overdue'        => $query->whereHas('lensOrder', fn($q) => $q->where('status', 'Ready')->whereDate('updated_at', '<=', now()->subDays(7))),
            'ready'          => $query->whereHas('lensOrder', fn($q) => $q->where('status', 'Ready')),
            'renewal_due'    => $query->whereHas('lensOrder', fn($q) =>
                                    $q->where('status', 'Collected')
                                      ->whereNotNull('renewal_date')
                                      ->whereDate('renewal_date', '<=', now()->addDays(30))
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
        $price = fn ($group) => $group->isEmpty() ? null : (float) $group->sum(fn ($item) => $item->subtotal ?? $item->total);

        return [
            'frame'       => $frames->first()?->product,
            'lens'        => $lenses->first()?->product,
            'frame_price' => $price($frames),
            'lens_price'  => $price($lenses),
        ];
    }

    public function canCreateOrderFromPos(array $posSummary): bool
    {
        return in_array($posSummary['status'] ?? null, ['partial', 'sold'], true)
            || (float) ($posSummary['paid'] ?? 0) > 0;
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

    public function render()
    {
        // Single grouped query instead of 5 individual count queries
        $statusCounts = LensOrder::selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $stats = [
            'pending'     => Refractions::where('dispensing_required', true)->doesntHave('lensOrder')->count(),
            'ordered'     => $statusCounts->get('Pending', 0),
            'in_lab'      => $statusCounts->get('In Lab', 0),
            'ready'       => $statusCounts->get('Ready', 0),
            'collected'   => $statusCounts->get('Collected', 0),
            'overdue'     => LensOrder::where('status', 'Ready')->where('updated_at', '<=', now()->subDays(7))->count(),
            'renewal_due' => LensOrder::where('status', 'Collected')
                                ->whereNotNull('renewal_date')
                                ->whereDate('renewal_date', '<=', now()->addDays(30))
                                ->whereNull('renewal_reminder_sent_at')
                                ->count(),
        ];

        $spectacles = $this->getFilteredQuery()->paginate(12);
        $activeRefraction = $spectacles->firstWhere('id', $this->activeRefractionId) ?? $spectacles->first();

        $frameSearchResults = [];
        $lensSearchResults  = [];

        if ($this->showFrameResults && strlen($this->frameSearchTerm) >= 2) {
            $frameSearchResults = Product::whereHas('category', fn($q) => $q->where('name', 'LIKE', '%frame%'))
                ->where('quantity', '>', 0)
                ->where(fn($q) =>
                    $q->where('name', 'LIKE', "%{$this->frameSearchTerm}%")
                      ->orWhere('batch_number', 'LIKE', "%{$this->frameSearchTerm}%")
                )
                ->limit(10)->get();
        }

        if ($this->showLensResults && strlen($this->lensSearchTerm) >= 2) {
            $lensSearchResults = Product::whereHas('category', fn($q) => $q->where('name', 'LIKE', '%lens%'))
                ->where('quantity', '>', 0)
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

        return view('livewire.secretary.spectacle-component', [
            'spectacles'         => $spectacles,
            'activeRefraction'   => $activeRefraction,
            'stats'              => $stats,
            'frameSearchResults' => $frameSearchResults,
            'lensSearchResults'  => $lensSearchResults,
            'printOrder'         => $printOrder,
            'orderPosProducts'   => $orderPosProducts,
            'appSettings'        => Setting::getSettings(),
            'doctors'            => User::whereIn('id', \App\Models\Consultations::whereNotNull('user_id')->select('user_id'))->orderBy('name')->get(['id', 'name']),
            'labs'               => LensOrder::whereNotNull('notes')->get()->map(fn($o) => $this->extractNoteValue($o, 'Lab'))->filter()->unique()->values(),
            'availableFrames'    => Product::whereHas('category', fn($q) => $q->where('name', 'like', '%frame%'))->where('quantity', '>', 0)->orderBy('name')->get(['id','name','quantity','selling_price']),
            'availableLenses'    => Product::whereHas('category', fn($q) => $q->where('name', 'like', '%lens%'))->where('quantity', '>', 0)->orderBy('name')->get(['id','name','quantity','selling_price']),
        ])->layout('layouts.secretary.secretary-layout');
    }
}
