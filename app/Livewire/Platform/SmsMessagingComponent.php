<?php

namespace App\Livewire\Platform;

use App\Models\{Clinic, PlatformInvoice, PlatformSetting, Setting, SmsBundle, SmsWallet};
use App\Services\Messaging\{PlatformSmsBalance, SmsCreditService};
use App\Services\{PlatformAuditService, SubscriptionBillingService};
use Livewire\Component;

/**
 * Platform-side control of the shared SMS gateway: provider balance, the SMS bundle
 * catalogue, clinic credit balances and top-ups, and sender ID approvals.
 */
class SmsMessagingComponent extends Component
{
    public array $rejectNotes = [];
    /** Setting id => the sender ID exactly as registered with the network, when it differs from the request. */
    public array $approveAs = [];

    // Platform override of a clinic's sender ID, without waiting for a request
    public ?int $editingSenderClinicId = null;
    public string $senderOverride = '';

    private const SENDER_RULE = ['regex:/^(?=.*[A-Za-z])[A-Za-z0-9 ]{3,11}$/'];
    private const SENDER_MESSAGE = 'Use 3–11 letters, digits or spaces, including at least one letter.';

    // Section tab: payments | credits | bundles | senders
    public string $section = 'credits';

    // Bundle catalogue form
    public ?int $editingBundleId = null;
    public string $bundleName = '';
    public ?int $bundleCredits = null;
    public ?string $bundlePrice = null;
    public string $bundleCurrency = 'GHS';

    // Allowed price per SMS credit; every bundle must fall inside it
    public ?string $minPerCredit = null;
    public ?string $maxPerCredit = null;

    // Manual credit grant / adjustment
    public ?int $creditClinicId = null;
    public ?int $creditAmount = null;
    public string $creditNote = '';

    // Payment for a pending SMS bundle invoice
    public array $paymentMethods = [];
    public array $paymentReferences = [];

    public function mount(): void
    {
        [$min, $max] = SmsBundle::priceRange();
        $this->minPerCredit = number_format($min, 3, '.', '');
        $this->maxPerCredit = number_format($max, 3, '.', '');

        // Open on whatever needs attention first.
        if (PlatformInvoice::where('source', 'sms_bundle')->whereIn('status', ['unpaid', 'partial'])->exists()) {
            $this->section = 'payments';
        } elseif (Setting::withoutGlobalScopes()->where('sms_sender_id_status', 'pending')->exists()) {
            $this->section = 'senders';
        }
    }

    public function setSection(string $section): void
    {
        abort_unless(in_array($section, ['payments', 'credits', 'bundles', 'senders'], true), 422);
        $this->section = $section;
        $this->resetErrorBag();
    }

    /** Pre-select a clinic in the credit adjustment form. */
    public function adjustFor(int $clinicId): void
    {
        $this->creditClinicId = $clinicId;
        $this->resetErrorBag();
    }

    public function cancelBundleEdit(): void
    {
        $this->reset(['editingBundleId', 'bundleName', 'bundleCredits', 'bundlePrice']);
        $this->resetErrorBag();
    }

    public function approve(int $settingId, PlatformAuditService $audit): void
    {
        $setting = Setting::withoutGlobalScopes()->where('sms_sender_id_status', 'pending')->findOrFail($settingId);
        $old = $setting->only(['sms_sender_id', 'sms_sender_id_status']);
        $as = trim((string) ($this->approveAs[$settingId] ?? ''));
        if ($as !== '') {
            $this->validate(["approveAs.$settingId" => self::SENDER_RULE], ["approveAs.$settingId.regex" => self::SENDER_MESSAGE]);
        }

        $setting->update([
            'sms_sender_id'        => $as !== '' ? $as : $setting->sms_sender_id_requested,
            'sms_sender_id_status' => 'approved',
            'sms_sender_id_note'   => null,
        ]);

        $audit->record('SMS_SENDER_ID_APPROVED', $setting->clinic_id, $old, $setting->only(['sms_sender_id', 'sms_sender_id_status']));
        unset($this->approveAs[$settingId]);
        session()->flash('sms_message', "Sender ID {$setting->sms_sender_id} approved.");
    }

    public function reject(int $settingId, PlatformAuditService $audit): void
    {
        $this->validate(["rejectNotes.$settingId" => 'required|string|max:255'], [], ["rejectNotes.$settingId" => 'rejection reason']);
        $setting = Setting::withoutGlobalScopes()->where('sms_sender_id_status', 'pending')->findOrFail($settingId);

        // Any previously approved sender (sms_sender_id) stays in use; only the new request is refused.
        $setting->update([
            'sms_sender_id_status' => 'rejected',
            'sms_sender_id_note'   => $this->rejectNotes[$settingId],
        ]);

        $audit->record('SMS_SENDER_ID_REJECTED', $setting->clinic_id, [], ['requested' => $setting->sms_sender_id_requested], $this->rejectNotes[$settingId]);
        unset($this->rejectNotes[$settingId]);
        session()->flash('sms_message', "Sender ID {$setting->sms_sender_id_requested} rejected.");
    }

    /** A hosted clinic's settings row; the sender ID is only the platform's to set for hosted clinics. */
    private function hostedSetting(int $clinicId): Setting
    {
        abort_unless(Clinic::whereKey($clinicId)->where('deployment_mode', 'hosted')->exists(), 404);
        return Setting::withoutGlobalScopes()->where('clinic_id', $clinicId)->firstOrFail();
    }

    public function editSender(int $clinicId): void
    {
        $this->editingSenderClinicId = $clinicId;
        $this->senderOverride = (string) $this->hostedSetting($clinicId)->sms_sender_id;
        $this->resetErrorBag();
    }

    public function cancelSenderEdit(): void
    {
        $this->reset(['editingSenderClinicId', 'senderOverride']);
        $this->resetErrorBag();
    }

    /** Set the clinic's sender ID directly. It replaces any request still pending. */
    public function saveSender(PlatformAuditService $audit): void
    {
        $this->senderOverride = trim($this->senderOverride);
        $this->validate(['senderOverride' => array_merge(['required'], self::SENDER_RULE)],
            ['senderOverride.regex' => self::SENDER_MESSAGE], ['senderOverride' => 'sender ID']);

        $setting = $this->hostedSetting((int) $this->editingSenderClinicId);
        $old = $setting->only(['sms_sender_id', 'sms_sender_id_requested', 'sms_sender_id_status']);
        $setting->update([
            'sms_sender_id'           => $this->senderOverride,
            'sms_sender_id_requested' => $this->senderOverride,
            'sms_sender_id_status'    => 'approved',
            'sms_sender_id_note'      => null,
        ]);

        $audit->record('SMS_SENDER_ID_OVERRIDDEN', $setting->clinic_id, $old, $setting->only(['sms_sender_id', 'sms_sender_id_requested', 'sms_sender_id_status']));
        $this->reset(['editingSenderClinicId', 'senderOverride']);
        session()->flash('sms_message', "Sender ID set to {$setting->sms_sender_id}".($old['sms_sender_id_status'] === 'pending' ? "; the pending request for {$old['sms_sender_id_requested']} was replaced." : '.'));
    }

    /** Send as the platform default sender again. */
    public function resetSender(int $clinicId, PlatformAuditService $audit): void
    {
        $setting = $this->hostedSetting($clinicId);
        $old = $setting->only(['sms_sender_id', 'sms_sender_id_requested', 'sms_sender_id_status']);
        $setting->update(['sms_sender_id' => null, 'sms_sender_id_requested' => null, 'sms_sender_id_status' => 'none', 'sms_sender_id_note' => null]);

        $audit->record('SMS_SENDER_ID_RESET', $clinicId, $old, $setting->only(['sms_sender_id', 'sms_sender_id_requested', 'sms_sender_id_status']));
        if ($this->editingSenderClinicId === $clinicId) $this->cancelSenderEdit();
        session()->flash('sms_message', 'Sender ID reset; the clinic now sends as the platform default.');
    }

    public function checkBalance(PlatformSmsBalance $balance): void
    {
        $status = $balance->check();
        session()->flash('sms_message', ($status['error'] ?? null) ? 'Balance check failed: ' . $status['error'] : 'Balance updated.');
    }

    public function editBundle(int $id): void
    {
        $bundle = SmsBundle::findOrFail($id);
        $this->editingBundleId = $bundle->id;
        $this->bundleName      = $bundle->name;
        $this->bundleCredits   = $bundle->credits;
        $this->bundlePrice     = (string) $bundle->price;
        $this->bundleCurrency  = $bundle->currency;
    }

    public function saveBundle(PlatformAuditService $audit): void
    {
        $data = $this->validate([
            'bundleName'     => 'required|string|max:100',
            'bundleCredits'  => 'required|integer|min:1|max:10000000',
            'bundlePrice'    => 'required|numeric|min:0.01',
            'bundleCurrency' => 'required|string|size:3',
        ]);

        [$min, $max] = SmsBundle::priceRange();
        $per = round((float) $data['bundlePrice'] / $data['bundleCredits'], 4);
        if ($per < $min || $per > $max) {
            $this->addError('bundlePrice', sprintf('That is %.3f per SMS. Keep it between %.3f and %.3f (the price range above), e.g. %s to %s for %s credits.',
                $per, $min, $max, number_format($min * $data['bundleCredits'], 2), number_format($max * $data['bundleCredits'], 2), number_format($data['bundleCredits'])));
            return;
        }

        $bundle = SmsBundle::updateOrCreate(['id' => $this->editingBundleId], [
            'name'     => $data['bundleName'],
            'credits'  => $data['bundleCredits'],
            'price'    => $data['bundlePrice'],
            'currency' => strtoupper($data['bundleCurrency']),
        ]);

        $audit->record($this->editingBundleId ? 'SMS_BUNDLE_UPDATED' : 'SMS_BUNDLE_CREATED', null, [], $bundle->toArray());
        $this->reset(['editingBundleId', 'bundleName', 'bundleCredits', 'bundlePrice']);
        session()->flash('sms_message', "Bundle {$bundle->name} saved. Invoices already issued keep their original price.");
    }

    public function savePriceRange(PlatformAuditService $audit): void
    {
        $data = $this->validate([
            'minPerCredit' => 'required|numeric|min:0.001|max:10',
            'maxPerCredit' => 'required|numeric|max:10|gte:minPerCredit',
        ], [], ['minPerCredit' => 'lowest price per SMS', 'maxPerCredit' => 'highest price per SMS']);

        $before = SmsBundle::priceRange();
        PlatformSetting::put(['sms_min_price_per_credit' => $data['minPerCredit'], 'sms_max_price_per_credit' => $data['maxPerCredit']]);
        $audit->record('SMS_PRICE_RANGE_UPDATED', null, ['min' => $before[0], 'max' => $before[1]],
            ['min' => (float) $data['minPerCredit'], 'max' => (float) $data['maxPerCredit']]);

        $outside = SmsBundle::where('is_active', true)->get()->reject->withinRange()->count();
        session()->flash('sms_message', 'Price range saved.'.($outside ? " {$outside} bundle(s) on sale are now outside it; edit their prices." : ''));
    }

    public function toggleBundle(int $id, PlatformAuditService $audit): void
    {
        $bundle = SmsBundle::findOrFail($id);
        $bundle->update(['is_active' => !$bundle->is_active]);
        $audit->record('SMS_BUNDLE_' . ($bundle->is_active ? 'ACTIVATED' : 'DEACTIVATED'), null, [], ['id' => $bundle->id, 'name' => $bundle->name]);
    }

    public function adjustCredits(SmsCreditService $credits, PlatformAuditService $audit): void
    {
        $data = $this->validate([
            'creditClinicId' => 'required|exists:clinics,id',
            'creditAmount'   => 'required|integer|not_in:0|between:-10000000,10000000',
            'creditNote'     => 'required|string|min:3|max:255',
        ], [], ['creditClinicId' => 'clinic', 'creditAmount' => 'credits', 'creditNote' => 'reason']);

        $transaction = $credits->add($data['creditClinicId'], $data['creditAmount'],
            $data['creditAmount'] > 0 ? 'grant' : 'adjustment', ['note' => $data['creditNote']]);

        $audit->record('SMS_CREDITS_ADJUSTED', $data['creditClinicId'], [], ['credits' => $data['creditAmount'],
            'balance_after' => $transaction->balance_after], $data['creditNote']);
        $this->reset(['creditClinicId', 'creditAmount', 'creditNote']);
        session()->flash('sms_message', "Credits updated. New balance: {$transaction->balance_after}.");
    }

    /** Record full payment for an SMS bundle invoice; the credits are added by the billing service. */
    public function markBundlePaid(int $invoiceId, SubscriptionBillingService $billing, PlatformAuditService $audit): void
    {
        $this->validate([
            "paymentMethods.$invoiceId"    => 'required|in:cash,bank_transfer,mobile_money,card',
            "paymentReferences.$invoiceId" => 'nullable|string|max:100',
        ], [], ["paymentMethods.$invoiceId" => 'payment method']);

        $invoice   = PlatformInvoice::where('source', 'sms_bundle')->findOrFail($invoiceId);
        $method    = $this->paymentMethods[$invoiceId];
        $reference = trim($this->paymentReferences[$invoiceId] ?? '') ?: null;

        $payment = $billing->allocatePayment($invoice, $invoice->balance(), $method, $reference, auth()->id(),
            $reference ? "manual:{$method}:{$reference}" : null);

        $audit->record('PAYMENT_ALLOCATED', $invoice->clinic_id, [], ['invoice' => $invoice->number, 'payment_id' => $payment->id, 'amount' => $payment->amount]);
        unset($this->paymentMethods[$invoiceId], $this->paymentReferences[$invoiceId]);
        session()->flash('sms_message', "Invoice {$invoice->number} paid; " . number_format($invoice->sms_credits) . ' credits added.');
    }

    public function render(PlatformSmsBalance $balance, SmsCreditService $credits)
    {
        $hostedIds = Clinic::where('deployment_mode', 'hosted')->pluck('id');
        $settings  = Setting::withoutGlobalScopes()->whereIn('clinic_id', $hostedIds)
            ->orderByRaw("sms_sender_id_status = 'pending' desc")->orderBy('sms_sender_id_requested_at')->get()->keyBy('clinic_id');
        $clinics   = Clinic::whereIn('id', $hostedIds)->orderBy('name')->get();
        $wallets   = SmsWallet::whereIn('clinic_id', $hostedIds)->get()->keyBy('clinic_id');

        return view('livewire.platform.sms-messaging-component', [
            'pending'        => $settings->where('sms_sender_id_status', 'pending')->map(fn ($s) => ['setting' => $s, 'clinic' => $clinics->firstWhere('id', $s->clinic_id)]),
            'clinics'        => $clinics,
            'settings'       => $settings,
            'wallets'        => $wallets,
            'bundles'        => SmsBundle::orderBy('sort_order')->orderBy('credits')->get(),
            'priceRange'     => SmsBundle::priceRange(),
            'bundleInvoices' => PlatformInvoice::with('clinic')->where('source', 'sms_bundle')->whereIn('status', ['unpaid', 'partial'])->oldest()->get(),
            'providerStatus' => $balance->last(),
            'outstanding'    => $credits->outstandingCredits(),
            'defaultSender'  => config('services.eazisms.default_sender'),
            'gatewayReady'   => filled(config('services.eazisms.url')) && filled(config('services.eazisms.key')),
        ])->layout('layouts.platform');
    }
}
