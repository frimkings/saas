<?php

namespace App\Livewire\Optical\Concerns;

use App\Models\LensOrder;
use App\Support\PaymentMethods;
use App\Services\OpticalCollectionNotifier;
use App\Services\OpticalOrderWorkflowService;

/**
 * The optical order side panel and everything it can do: move the order along, take
 * payment, notify the customer, receive special-order lenses, refund and cancel.
 * Shared by Orders, Lab Workbench and Awaiting Collection; the markup is the
 * livewire.optical.partials.order-panel view.
 */
trait ManagesOrderPanel
{
    public ?int $viewOrderId = null;
    public bool $showDeleteOrderModal = false;
    public ?int $orderToDeleteId = null;
    public string $orderToDeleteNumber = '';
    public bool $showPaymentModal = false;
    public ?int $paymentOrderId = null;
    public string $paymentAmount = '';
    public string $paymentMethod = 'cash';
    public bool $showRefundModal = false;
    public ?int $refundOrderId = null;
    public string $refundOrderNumber = '';
    public float $refundPaid = 0;
    public string $refundAmount = '';
    public string $refundReasonCode = '';
    public string $refundReason = '';
    public bool $showSendToLabModal = false;
    public ?int $sendToLabOrderId = null;
    public string $labSupplierId = '';
    public string $expectedBack = '';
    public bool $showConvertModal = false;
    public ?int $convertOrderId = null;
    public string $convertDeposit = '';
    public string $convertMethod = 'cash';

    /** Start the payment pickers on the clinic's first optical method. */
    public function mountManagesOrderPanel(): void
    {
        $this->paymentMethod = $this->convertMethod = PaymentMethods::first(PaymentMethods::OPTICAL);
    }
    public string $convertPricing = '';

    public function openOrder(int $id): void
    {
        $this->viewOrderId = LensOrder::findOrFail($id)->id;
        $this->resetErrorBag();
    }

    public function closeOrder(): void
    {
        $this->viewOrderId = null;
        $this->resetErrorBag();
    }

    /** The panel is closed in the browser (dismissLocal); tidy up when the server hears of it. */
    public function updatedViewOrderId($value): void
    {
        if ($value === null) $this->resetErrorBag();
    }

    /** The order shown in the panel, with what the panel displays. */
    public function panelOrder(): ?LensOrder
    {
        return $this->viewOrderId ? LensOrder::with(['patient', 'refraction.consultation.patient', 'user', 'frameProduct', 'lensProduct', 'frameOpticalProduct',
            'serviceLines', 'lensLines', 'remakeOf', 'refundLog', 'partnerClinic', 'purchaseOrderLines.purchaseOrder', 'labSupplier', 'events.user'])->find($this->viewOrderId) : null;
    }

    public function updateStatus($orderId, $newStatus): void
    {
        $workflow = app(OpticalOrderWorkflowService::class);
        $order = $workflow->transition((int) $orderId, (string) $newStatus);
        session()->flash('success', "Order {$order->order_id} marked as {$newStatus}.".\App\Livewire\Optical\OpticalCollectionsComponent::readySmsNote($workflow->lastReadySms));
    }

    public function sendCollectionSms($orderId, $kind): void
    {
        $order = LensOrder::whereIn('status', ['Ready for Collection', 'Ready'])->findOrFail((int) $orderId);
        $result = app(OpticalCollectionNotifier::class)->sendSms($order, (string) $kind);
        $result['success']
            ? session()->flash('success', "SMS sent for {$order->order_id}.")
            : $this->addError('order', 'SMS not sent: '.$result['error']);
    }

    public function recordPanelWhatsApp($orderId, $kind): void
    {
        $order = LensOrder::whereIn('status', ['Ready for Collection', 'Ready'])->findOrFail((int) $orderId);
        app(OpticalCollectionNotifier::class)->recordWhatsApp($order, (string) $kind);
    }

    public function receiveLens($orderId, $eye): void
    {
        $order = app(OpticalOrderWorkflowService::class)->receiveSpecialOrderLens((int) $orderId, (string) $eye);
        session()->flash('success', 'Special-order '.strtoupper((string) $eye)." lens received for {$order->order_id}.");
    }

    /** Converting asks for the deposit, and for an expired quotation whether to keep its prices. */
    public function convertQuotationToOrder($orderId): void
    {
        $quotation = LensOrder::where('status', 'Quotation')->findOrFail((int) $orderId);
        $this->convertOrderId = $quotation->id;
        $minimum = app(OpticalOrderWorkflowService::class)->minimumDeposit($quotation);
        $this->convertDeposit = $minimum > 0 ? number_format($minimum, 2, '.', '') : '';
        $this->convertMethod = PaymentMethods::first(PaymentMethods::OPTICAL);
        // A valid quote keeps its prices unless repriced; an expired one must choose.
        $this->convertPricing = $quotation->isQuoteExpired() ? '' : 'keep';
        $this->resetValidation();
        $this->showConvertModal = true;
    }

    public function confirmConvertQuotation(): void
    {
        $quotation = LensOrder::where('status', 'Quotation')->findOrFail((int) $this->convertOrderId);
        $this->validate([
            'convertDeposit' => 'nullable|numeric|min:0',
            'convertMethod' => ['required', \Illuminate\Validation\Rule::in(PaymentMethods::keys(PaymentMethods::OPTICAL))],
            'convertPricing' => $quotation->isQuoteExpired() ? 'required|in:keep,reprice' : 'nullable|in:keep,reprice',
        ], ['convertPricing.required' => 'This quotation has expired. Choose which prices to charge.']);
        $order = app(OpticalOrderWorkflowService::class)->activateQuotation(
            $quotation->id, (float) ($this->convertDeposit ?: 0), $this->convertMethod,
            $this->convertPricing === '' ? null : $this->convertPricing === 'reprice',
        );
        $this->showConvertModal = false;
        $this->convertOrderId = null;
        session()->flash('success', "Quotation converted to order {$order->order_id}.");
    }

    public function openSendToLab($orderId): void
    {
        $order = LensOrder::where('status', 'Pending')->findOrFail((int) $orderId);
        $this->sendToLabOrderId = $order->id;
        $this->labSupplierId = (string) ($order->lab_supplier_id ?? '');
        $this->expectedBack = '';
        $this->resetValidation();
        $this->showSendToLabModal = true;
    }

    /**
     * Default the return date from the chosen lab lead time. The page fills it in the browser as
     * the lab is chosen (each option carries its lead time), so a date already there is kept.
     */
    public function updatedLabSupplierId($value): void
    {
        if ($this->expectedBack !== '') return;
        $lead = ctype_digit((string) $value) ? \App\Models\Supplier::whereKey((int) $value)->value('lead_time_days') : null;
        $this->expectedBack = $lead ? today()->addDays((int) $lead)->toDateString() : '';
    }

    public function sendToLab(): void
    {
        $this->validate(['labSupplierId' => 'nullable|integer', 'expectedBack' => 'nullable|date|after_or_equal:today']);
        $order = app(OpticalOrderWorkflowService::class)->transition((int) $this->sendToLabOrderId, 'Sent to Lab',
            $this->labSupplierId === '' ? null : (int) $this->labSupplierId, $this->expectedBack ?: null);
        $this->showSendToLabModal = false;
        $this->sendToLabOrderId = null;
        session()->flash('success', "Order {$order->order_id} sent to ".($order->labSupplier?->name ?? 'the lab').($order->expected_back_at ? ', due back '.$order->expected_back_at->format('d M') : '').'.');
    }

    public function remake($id)
    {
        $order = LensOrder::whereIn('status', ['Ready for Collection', 'Ready', 'Collected'])->findOrFail((int) $id);
        return redirect()->route('optical.orders.create', ['remake_of' => $order->id]);
    }

    public function openRefundModal($id): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403);
        $order = LensOrder::whereNotIn('status', ['Quotation', 'Cancelled', 'Collected'])->findOrFail((int) $id);
        abort_unless((float) $order->paid_amount > 0, 404);
        $this->refundOrderId = $order->id;
        $this->refundOrderNumber = $order->order_id;
        $this->refundPaid = (float) $order->paid_amount;
        $this->refundAmount = number_format((float) $order->paid_amount, 2, '.', '');
        $this->refundReasonCode = '';
        $this->refundReason = '';
        $this->resetValidation();
        $this->showRefundModal = true;
    }

    public function refundOrder(): void
    {
        $this->validate([
            'refundOrderId' => 'required|integer',
            'refundAmount' => 'required|numeric|gt:0',
            'refundReasonCode' => 'required|in:'.implode(',', array_keys(\App\Models\RefundLog::REASON_CODES)),
            'refundReason' => 'required|string|min:10|max:500',
        ]);
        $order = app(OpticalOrderWorkflowService::class)->refundAndCancel(
            (int) $this->refundOrderId, (float) $this->refundAmount, $this->refundReasonCode, $this->refundReason
        );
        $this->showRefundModal = false;
        $this->refundOrderId = null;
        session()->flash('success', "Order {$order->order_id} refunded and cancelled.".((float) $order->cancellation_fee > 0 ? ' Cancellation fee kept: '.currency().' '.number_format((float) $order->cancellation_fee, 2).'.' : ''));
    }

    public function confirmDeleteOrder($id): void
    {
        $order = LensOrder::findOrFail((int) $id);
        if ((float) $order->paid_amount > 0) {
            if (auth()->user()?->hasAnyRole(['Manager', 'Super Admin'])) {
                $this->openRefundModal($order->id);
            } else {
                $this->addError('order', "Order {$order->order_id} has payments. Ask a manager to refund and cancel it.");
            }
            return;
        }
        $this->orderToDeleteId = $order->id;
        $this->orderToDeleteNumber = $order->order_id;
        $this->showDeleteOrderModal = true;
    }

    public function deleteOrder(): void
    {
        if (! $this->orderToDeleteId) return;
        $order = app(OpticalOrderWorkflowService::class)->cancel($this->orderToDeleteId);
        $this->showDeleteOrderModal = false;
        $this->orderToDeleteId = null;
        $this->orderToDeleteNumber = '';
        session()->flash('success', "Order {$order->order_id} cancelled.");
    }

    public function openPaymentModal($id): void
    {
        $order = LensOrder::findOrFail((int) $id);
        if (! $order->sale_id) {
            $this->addError('order', "Order {$order->order_id} is a clinic order: take its payment on the patient's clinic bill at reception or the cashier.");
            return;
        }
        if ($order->total <= (float) $order->paid_amount) {
            $this->addError('order', "Order {$order->order_id} is already fully paid.");
            return;
        }
        $this->paymentOrderId = $order->id;
        $this->paymentAmount = number_format($order->total - (float) $order->paid_amount, 2, '.', '');
        $this->showPaymentModal = true;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'paymentOrderId' => 'required|integer',
            'paymentAmount' => 'required|numeric|gt:0',
            'paymentMethod' => ['required', \Illuminate\Validation\Rule::in(PaymentMethods::keys(PaymentMethods::OPTICAL))],
        ]);
        app(OpticalOrderWorkflowService::class)->recordPayment(
            (int) $this->paymentOrderId, (float) $this->paymentAmount, $this->paymentMethod
        );
        $this->showPaymentModal = false;
        $this->paymentOrderId = null;
        session()->flash('success', 'Payment recorded.');
    }
}
