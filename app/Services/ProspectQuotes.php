<?php

namespace App\Services;

use App\Console\Commands\CreateAttendanceSuitePlanCommand;
use App\Mail\OwnerNoticeMail;
use App\Models\PlatformSetting;
use App\Models\PricingRequest;
use App\Models\ProspectQuote;
use App\Models\SubscriptionPlan;
use App\Services\Messaging\SmsCredentialResolver;
use App\Services\Messaging\SmsDriver;
use App\Support\PlanProduct;
use App\Support\Pricing\AttendanceSuitePrices;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Quotations and pro-forma invoices for prospects (Platform → Quotes): numbering, a first draft
 * from a pricing-page request, totals, the PDF, and sending it by email or SMS.
 */
class ProspectQuotes
{
    public const VALID_DAYS = 30;
    public const TERMS_SETTING = 'quote_terms';

    /** QUO-2026-0001 / PF-2026-0001: one run per document type per year. */
    public function nextNumber(string $type): string
    {
        $prefix = ProspectQuote::PREFIXES[$type] . '-' . now()->year . '-';
        $last = ProspectQuote::where('number', 'like', $prefix . '%')->lockForUpdate()->orderByDesc('number')->value('number');

        return $prefix . str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Payment details and conditions printed under every new quote until changed. */
    public static function defaultTerms(): string
    {
        return (string) (PlatformSetting::get(self::TERMS_SETTING) ?? '');
    }

    /** @return array{subtotal: float, tax: float, total: float} */
    public static function totals(array $lines, float $discount, float $taxRate): array
    {
        $subtotal = round(array_sum(array_map(fn ($line) => (float) $line['amount'], $lines)), 2);
        $taxable = max(0, $subtotal - $discount);
        $tax = round($taxable * $taxRate / 100, 2);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => round($taxable + $tax, 2)];
    }

    /**
     * The quote as the request priced it, for its billing interval. Special attendance pricing has
     * no list price, so its plan line comes in at 0 for the platform to fill in.
     */
    public function draftFromRequest(PricingRequest $request): array
    {
        $sel = $request->selection ?? [];
        $yearly = $request->billing_interval === 'yearly';
        $attendance = ($sel['product'] ?? null) === 'attendance';
        $addons = array_map(fn ($line) => [
            'description' => (string) $line[0],
            'amount' => round($yearly ? ($attendance ? $line[1] * AttendanceSuitePrices::YEARLY_MONTHS : ($line[2] ?? 12 * $line[1])) : $line[1], 2),
        ], $sel['lines'] ?? []);
        $plan = $attendance ? SubscriptionPlan::where('family_code', CreateAttendanceSuitePlanCommand::FAMILY)->where('is_active', true)->orderByDesc('version')->first()
            : $request->plan;
        $period = $yearly ? ' (12 months)' : ' (1 month)';
        $planLine = ['description' => ($attendance ? $request->plan_name : $request->plan_name . ' plan') . $period,
            'amount' => round(max(0, (float) $request->quoted_total - array_sum(array_column($addons, 'amount'))), 2)];

        return [
            'pricing_request_id' => $request->id, 'subscription_plan_id' => $plan?->id,
            'product' => $attendance ? PlanProduct::ATTENDANCE : ($plan?->product ?: PlanProduct::BOTH),
            'business_name' => $request->clinic_name, 'contact_name' => $request->contact_name, 'phone' => $request->phone,
            'email' => $request->email, 'address' => $request->town, 'billing_interval' => $request->billing_interval,
            'currency' => $request->currency ?: 'GHS', 'lines' => array_merge([$planLine], $addons),
            'tax_rate' => (float) ($plan?->tax_rate ?? 0),
        ];
    }

    /** Saves a new quote with the next number of its type. */
    public function create(array $data, ?int $userId): ProspectQuote
    {
        return DB::transaction(function () use ($data, $userId) {
            $totals = self::totals($data['lines'], (float) ($data['discount'] ?? 0), (float) ($data['tax_rate'] ?? 0));

            return ProspectQuote::create($data + $totals + [
                'number' => $this->nextNumber($data['document_type']), 'status' => 'draft', 'token' => Str::random(48), 'created_by' => $userId,
                'issued_on' => today(), 'valid_until' => today()->addDays(self::VALID_DAYS),
            ]);
        });
    }

    public function update(ProspectQuote $quote, array $data): ProspectQuote
    {
        // The number follows the document type: a quotation re-issued as a pro-forma gets a PF number.
        if (isset($data['document_type']) && $data['document_type'] !== $quote->document_type) {
            $data['number'] = DB::transaction(fn () => $this->nextNumber($data['document_type']));
        }
        $quote->update($data + self::totals($data['lines'] ?? $quote->lines, (float) ($data['discount'] ?? $quote->discount), (float) ($data['tax_rate'] ?? $quote->tax_rate)));

        return $quote;
    }

    public function pdf(ProspectQuote $quote)
    {
        return Pdf::loadView('pdf.prospect-quote', ['quote' => $quote, 'support' => PlatformSetting::support()]);
    }

    public function per(ProspectQuote $quote): string
    {
        return $quote->billing_interval === 'yearly' ? 'a year' : 'a month';
    }

    /** Emails the document as a PDF attachment. @return string|null an error to show, or null when sent */
    public function email(ProspectQuote $quote): ?string
    {
        if (! filter_var($quote->email, FILTER_VALIDATE_EMAIL)) return 'Add a valid email address first.';
        $support = PlatformSetting::support();
        $type = $quote->typeLabel();
        $mail = new OwnerNoticeMail(
            (string) $support['name'],
            "{$type} {$quote->number}",
            'Hello ' . ($quote->contact_name ?: $quote->business_name) . ", please find attached our " . strtolower($type) . " for {$quote->business_name}.",
            ['Total' => "{$quote->currency} " . number_format((float) $quote->total, 2) . ' ' . $this->per($quote),
                'Valid until' => $quote->valid_until->format('j M Y')] + ($support['phone'] ? ['Questions' => $support['phone']] : []),
            "Open the " . strtolower($type), $quote->publicUrl(),
            null, "Sent by {$support['name']}. Reply to this email with any questions.",
        );
        $mail->subject("{$type} {$quote->number} from {$support['name']}")
            ->attachData($this->pdf($quote)->output(), "{$quote->number}.pdf", ['mime' => 'application/pdf']);
        if ($support['email']) $mail->replyTo($support['email']);

        try {
            Mail::to($quote->email)->send($mail);
        } catch (\Throwable $e) {
            report($e);

            return 'The email could not be sent: ' . Str::limit($e->getMessage(), 160);
        }
        $this->markSent($quote);

        return null;
    }

    public function smsText(ProspectQuote $quote): string
    {
        $support = PlatformSetting::support();

        return 'Hello ' . ($quote->contact_name ?: $quote->business_name) . ", your " . strtolower($quote->typeLabel()) . " {$quote->number} from {$support['name']}: "
            . "{$quote->currency} " . number_format((float) $quote->total, 2) . ' ' . $this->per($quote) . ', valid until ' . $quote->valid_until->format('j M Y')
            . ". View it: {$quote->publicUrl()}";
    }

    /** Texts the prospect a link to the PDF through the platform's SMS account. @return string|null an error, or null when sent */
    public function sms(ProspectQuote $quote): ?string
    {
        if (strlen(preg_replace('/\D/', '', (string) $quote->phone)) < 9) return 'Add a phone number first.';
        try {
            $result = app(SmsDriver::class)->send(app(SmsCredentialResolver::class)->platform(), (string) $quote->phone, $this->smsText($quote));
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        if (! ($result['success'] ?? false)) return 'The SMS was not sent: ' . ($result['error'] ?? 'the gateway refused it.');
        $this->markSent($quote);

        return null;
    }

    private function markSent(ProspectQuote $quote): void
    {
        $quote->update(['sent_at' => now()] + ($quote->status === 'draft' ? ['status' => 'sent'] : []));
        if ($quote->pricingRequest?->status === 'new') $quote->pricingRequest->update(['status' => 'contacted', 'handled_by' => auth()->id(), 'handled_at' => now()]);
    }

    /** Called once the business has been onboarded: the quote is accepted and its request won. */
    public function converted(ProspectQuote $quote, int $clinicId): void
    {
        $quote->update(['status' => 'accepted', 'clinic_id' => $clinicId, 'decided_at' => $quote->decided_at ?? now()]);
        $quote->pricingRequest?->update(['status' => 'won', 'handled_by' => auth()->id(), 'handled_at' => now()]);
    }
}
