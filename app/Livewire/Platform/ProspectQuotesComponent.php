<?php

namespace App\Livewire\Platform;

use App\Models\PlatformSetting;
use App\Models\PricingRequest;
use App\Models\ProspectQuote;
use App\Models\SubscriptionPlan;
use App\Services\PlatformAuditService;
use App\Services\ProspectQuotes;
use App\Support\PlanProduct;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Platform → Quotes: quotations and pro-forma invoices for businesses that are not on the platform
 * yet, written from a pricing-page request or from scratch, sent by email or SMS, and turned into
 * a clinic through Clinics → Onboard new clinic once accepted.
 */
class ProspectQuotesComponent extends Component
{
    use WithPagination;

    /** A pricing request to start a quote from (the "Make a quote" button in the requests inbox). */
    #[Url(as: 'request', except: null)]
    public ?int $fromRequest = null;

    public string $status = 'open';
    public string $search = '';
    public bool $showEditor = false;
    public ?int $editingId = null;
    public array $form = [];
    public string $defaultTerms = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $this->defaultTerms = ProspectQuotes::defaultTerms();
        if ($this->fromRequest && $request = PricingRequest::find($this->fromRequest)) {
            $this->startForm(app(ProspectQuotes::class)->draftFromRequest($request));
        }
        $this->fromRequest = null;
    }

    private function startForm(array $data = []): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->form = [
            'document_type' => 'quotation', 'pricing_request_id' => $data['pricing_request_id'] ?? null,
            'subscription_plan_id' => (string) ($data['subscription_plan_id'] ?? ''), 'product' => $data['product'] ?? PlanProduct::BOTH,
            'business_name' => $data['business_name'] ?? '', 'contact_name' => $data['contact_name'] ?? '', 'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '', 'address' => $data['address'] ?? '', 'billing_interval' => $data['billing_interval'] ?? 'monthly',
            'currency' => $data['currency'] ?? 'GHS',
            'lines' => array_map(fn ($l) => ['description' => $l['description'], 'amount' => self::plain($l['amount'])], $data['lines'] ?? [['description' => '', 'amount' => '']]),
            'discount' => '', 'tax_rate' => self::plain($data['tax_rate'] ?? 0), 'valid_until' => today()->addDays(ProspectQuotes::VALID_DAYS)->toDateString(),
            'notes' => '', 'terms' => $this->defaultTerms,
        ];
        $this->showEditor = true;
    }

    private static function plain($amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }

    public function newQuote(): void
    {
        $this->startForm();
    }

    public function edit(int $id): void
    {
        $quote = ProspectQuote::findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $quote->id;
        $this->form = $quote->only(['document_type', 'pricing_request_id', 'product', 'business_name', 'contact_name', 'phone', 'email', 'address', 'billing_interval', 'currency', 'notes', 'terms'])
            + ['subscription_plan_id' => (string) $quote->subscription_plan_id, 'discount' => (float) $quote->discount ? self::plain($quote->discount) : '',
                'tax_rate' => self::plain($quote->tax_rate), 'valid_until' => $quote->valid_until->toDateString(),
                'lines' => array_map(fn ($l) => ['description' => $l['description'], 'amount' => self::plain($l['amount'])], $quote->lines)];
        $this->form = array_map(fn ($v) => $v ?? '', $this->form);
        $this->showEditor = true;
    }

    public function closeEditor(): void
    {
        $this->showEditor = false;
        $this->editingId = null;
    }

    public function addLine(): void
    {
        $this->form['lines'][] = ['description' => '', 'amount' => ''];
    }

    public function removeLine(int $index): void
    {
        if (count($this->form['lines']) <= 1) return;
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    /** Choosing a plan sets the product it belongs to (used when the business is onboarded). */
    public function updatedFormSubscriptionPlanId($id): void
    {
        if ($product = SubscriptionPlan::whereKey($id)->value('product')) $this->form['product'] = $product;
    }

    public function save(ProspectQuotes $quotes, PlatformAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        if ($this->editingId && ProspectQuote::whereKey($this->editingId)->value('status') === 'accepted') {
            $this->addError('form', 'This quote was accepted and the business onboarded, so it can no longer be changed.');
            return;
        }
        $d = $this->validate([
            'form.document_type' => ['required', Rule::in(array_keys(ProspectQuote::TYPES))],
            'form.business_name' => 'required|string|max:150', 'form.contact_name' => 'nullable|string|max:120',
            'form.phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s-]{9,}$/'], 'form.email' => 'nullable|email|max:150',
            'form.address' => 'nullable|string|max:255', 'form.billing_interval' => 'required|in:monthly,yearly', 'form.currency' => 'required|alpha|size:3',
            'form.subscription_plan_id' => 'nullable|exists:subscription_plans,id', 'form.product' => ['required', Rule::in(array_keys(PlanProduct::PRODUCTS))],
            'form.lines' => 'required|array|min:1|max:30', 'form.lines.*.description' => 'required|string|max:200', 'form.lines.*.amount' => 'required|numeric|min:0|max:10000000',
            'form.discount' => 'nullable|numeric|min:0|max:10000000', 'form.tax_rate' => 'nullable|numeric|min:0|max:100',
            'form.valid_until' => 'required|date|after_or_equal:today', 'form.notes' => 'nullable|string|max:2000', 'form.terms' => 'nullable|string|max:3000',
        ], [
            'form.business_name.required' => 'Enter the business name.', 'form.phone.regex' => 'Enter a phone number that can receive an SMS.',
            'form.lines.*.description.required' => 'Describe this line.', 'form.lines.*.amount.*' => 'Enter an amount.',
            'form.valid_until.after_or_equal' => 'Choose today or a later date.',
        ])['form'];

        $lines = array_map(fn ($l) => ['description' => trim($l['description']), 'amount' => round((float) $l['amount'], 2)], $d['lines']);
        if ((float) ($d['discount'] ?: 0) > array_sum(array_column($lines, 'amount'))) {
            $this->addError('form.discount', 'The discount is more than the lines add up to.');
            return;
        }
        $data = ['lines' => $lines, 'discount' => round((float) ($d['discount'] ?: 0), 2), 'tax_rate' => round((float) ($d['tax_rate'] ?: 0), 2),
            'subscription_plan_id' => $d['subscription_plan_id'] ?: null, 'currency' => strtoupper($d['currency'])]
            + collect($d)->only(['document_type', 'business_name', 'billing_interval', 'product', 'valid_until'])->all()
            + collect($d)->only(['contact_name', 'phone', 'email', 'address', 'notes', 'terms'])->map(fn ($v) => trim((string) $v) ?: null)->all();

        if ($this->editingId) {
            $quote = ProspectQuote::findOrFail($this->editingId);
            $old = $quote->only(['number', 'total', 'status']);
            $quotes->update($quote, $data);
            $audit->record('PROSPECT_QUOTE_UPDATED', null, $old, $quote->only(['number', 'business_name', 'total']));
        } else {
            $quote = $quotes->create($data + ['pricing_request_id' => $this->form['pricing_request_id'] ?: null], auth()->id());
            $audit->record('PROSPECT_QUOTE_CREATED', null, [], $quote->only(['number', 'business_name', 'total', 'pricing_request_id']));
        }
        // The payment details typed here become the default for the next quote.
        if ($data['terms'] !== null && $data['terms'] !== $this->defaultTerms) {
            PlatformSetting::put([ProspectQuotes::TERMS_SETTING => $data['terms']]);
            $this->defaultTerms = $data['terms'];
        }
        $this->editingId = $quote->id;
        $this->dispatch('notify', type: 'success', message: "{$quote->number} saved. Download it, or send it by email or SMS.");
    }

    public function sendEmail(int $id, ProspectQuotes $quotes, PlatformAuditService $audit): void
    {
        $this->send($id, 'email', fn ($quote) => $quotes->email($quote), $audit);
    }

    public function sendSms(int $id, ProspectQuotes $quotes, PlatformAuditService $audit): void
    {
        $this->send($id, 'sms', fn ($quote) => $quotes->sms($quote), $audit);
    }

    private function send(int $id, string $channel, callable $deliver, PlatformAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        $quote = ProspectQuote::findOrFail($id);
        if ($error = $deliver($quote)) {
            $this->dispatch('notify', type: 'error', message: $error);
            return;
        }
        $audit->record('PROSPECT_QUOTE_SENT', null, [], ['number' => $quote->number, 'channel' => $channel, 'to' => $channel === 'sms' ? $quote->phone : $quote->email]);
        $this->dispatch('notify', type: 'success', message: "{$quote->number} sent to " . ($channel === 'sms' ? $quote->phone : $quote->email) . '.');
    }

    public function decide(int $id, string $status, PlatformAuditService $audit): void
    {
        abort_unless(auth()->user()?->is_platform_admin, 403);
        abort_unless(in_array($status, ['sent', 'declined'], true), 422);
        $quote = ProspectQuote::findOrFail($id);
        abort_if($quote->status === 'accepted', 422);
        $old = $quote->status;
        $quote->update(['status' => $status, 'decided_at' => $status === 'declined' ? now() : null]);
        if ($status === 'declined' && $quote->pricingRequest && $quote->pricingRequest->status !== 'won') {
            $quote->pricingRequest->update(['status' => 'lost', 'handled_by' => auth()->id(), 'handled_at' => now()]);
        }
        $audit->record('PROSPECT_QUOTE_STATUS', null, ['status' => $old], ['number' => $quote->number, 'status' => $status]);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = ProspectQuote::with(['clinic', 'creator'])->latest('id')
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($x) => $x->where('business_name', 'like', '%' . trim($this->search) . '%')
                ->orWhere('number', 'like', '%' . trim($this->search) . '%')->orWhere('phone', 'like', '%' . trim($this->search) . '%')));
        if ($this->status === 'open') $query->whereIn('status', ['draft', 'sent']);
        elseif ($this->status !== 'all') $query->where('status', $this->status);

        return view('livewire.platform.prospect-quotes-component', [
            'quotes' => $query->paginate(20),
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('product')->orderBy('base_price')->get(['id', 'name', 'product']),
            'editing' => $this->editingId ? ProspectQuote::find($this->editingId) : null,
            'preview' => $this->showEditor ? $this->previewTotals() : null,
        ])->layout('layouts.platform');
    }

    private function previewTotals(): array
    {
        $lines = array_map(fn ($l) => ['amount' => is_numeric($l['amount'] ?? null) ? (float) $l['amount'] : 0], $this->form['lines'] ?? []);

        return ProspectQuotes::totals($lines, is_numeric($this->form['discount'] ?? null) ? (float) $this->form['discount'] : 0,
            is_numeric($this->form['tax_rate'] ?? null) ? (float) $this->form['tax_rate'] : 0);
    }
}
