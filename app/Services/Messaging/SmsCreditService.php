<?php

namespace App\Services\Messaging;

use App\Models\{Clinic, PlatformInvoice, SmsBundle, SmsCreditTransaction, SmsLog, SmsWallet};
use App\Services\{BillingNotificationService, NotificationService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Prepaid SMS credits for hosted clinics. Credits never expire; one credit pays for one
 * SMS part. Every balance change locks the clinic's wallet row and writes a transaction.
 */
class SmsCreditService
{
    public function balance(int $clinicId): int
    {
        return (int) SmsWallet::where('clinic_id', $clinicId)->value('balance');
    }

    /**
     * Take credits and create the SMS log in one locked transaction, so two sends at the
     * same moment can never both spend the last credit.
     *
     * @param  callable(): SmsLog  $createLog
     * @throws InsufficientSmsCreditsException
     */
    public function charge(int $clinicId, int $credits, callable $createLog): SmsLog
    {
        $crossedLow = false;

        $log = DB::transaction(function () use ($clinicId, $credits, $createLog, &$crossedLow) {
            $wallet = $this->lockedWallet($clinicId);

            if ($wallet->balance < $credits) {
                throw new InsufficientSmsCreditsException($wallet->balance, $credits);
            }

            $log = $createLog();
            $log->update(['charged_credits' => $credits]);
            $wallet->balance -= $credits;

            if ($wallet->balance < $wallet->lowBalanceThreshold() && !$wallet->low_balance_notified_at) {
                $wallet->low_balance_notified_at = now();
                $crossedLow = true;
            }

            $wallet->save();
            $this->record($wallet, 'usage', -$credits, ['sms_log_id' => $log->id]);

            return $log;
        });

        if ($crossedLow) {
            $this->notifyClinic('sms_credits_low', 'SMS credits running low',
                "Only {$this->balance($clinicId)} SMS credits are left. Buy more in Communications → SMS Credits & Sending to keep messages going.");
            app(\App\Services\OwnerAlerts::class)->smsCreditsLow($clinicId, $this->balance($clinicId));
        }

        return $log;
    }

    /** Give back the credits of an SMS that was never delivered. Safe to call more than once. */
    public function refund(SmsLog $log): void
    {
        if (!$log->charged_credits) {
            return;
        }

        DB::transaction(function () use ($log) {
            $log = SmsLog::withoutGlobalScopes()->lockForUpdate()->find($log->id);
            if (!$log || !$log->charged_credits) {
                return;
            }

            $wallet = $this->lockedWallet($log->clinic_id);
            $wallet->balance += $log->charged_credits;
            $wallet->save();
            $this->record($wallet, 'refund', $log->charged_credits, ['sms_log_id' => $log->id, 'note' => 'SMS not delivered']);
            $log->update(['charged_credits' => 0]);
        });
    }

    /** Add (or, for adjustments, remove) credits. Purchases and grants count as a top-up for low-balance warnings. */
    public function add(int $clinicId, int $credits, string $type, array $attributes = []): SmsCreditTransaction
    {
        if ($credits === 0) {
            throw ValidationException::withMessages(['credits' => 'Enter a non-zero number of credits.']);
        }

        return DB::transaction(function () use ($clinicId, $credits, $type, $attributes) {
            if (!empty($attributes['idempotency_key'])
                && ($existing = SmsCreditTransaction::where('idempotency_key', $attributes['idempotency_key'])->first())) {
                return $existing;
            }

            $wallet = $this->lockedWallet($clinicId);
            if ($wallet->balance + $credits < 0) {
                throw ValidationException::withMessages(['credits' => "The clinic only has {$wallet->balance} credits; the balance cannot go below zero."]);
            }

            $wallet->balance += $credits;
            if ($credits > 0 && in_array($type, ['purchase', 'grant'], true)) {
                $wallet->last_topup_credits = $credits;
            }
            if ($wallet->balance >= $wallet->lowBalanceThreshold()) {
                $wallet->low_balance_notified_at = null;
            }
            $wallet->save();

            return $this->record($wallet, $type, $credits, $attributes);
        });
    }

    /** Called when an SMS bundle invoice is fully paid. Credits are added exactly once per invoice. */
    public function creditFromInvoice(PlatformInvoice $invoice): void
    {
        if ($invoice->source !== 'sms_bundle' || !$invoice->sms_credits) {
            return;
        }

        $this->add($invoice->clinic_id, (int) $invoice->sms_credits, 'purchase', [
            'platform_invoice_id' => $invoice->id,
            'sms_bundle_id'       => $invoice->sms_bundle_id,
            'note'                => "Invoice {$invoice->number} paid",
            'idempotency_key'     => 'invoice:' . $invoice->id,
        ]);
    }

    /** A clinic asks to buy a bundle: issue an invoice; credits follow once it is paid. */
    public function requestBundle(Clinic $clinic, SmsBundle $bundle): PlatformInvoice
    {
        abort_unless($bundle->is_active, 422, 'This SMS bundle is no longer available.');

        return $this->issueInvoice($clinic, (float) $bundle->price, $bundle->currency, $bundle->credits, $bundle->id,
            "SMS bundle: {$bundle->name} (" . number_format($bundle->credits) . ' credits)');
    }

    /**
     * A clinic types its own amount: credits are worked out here (never trusted from the browser)
     * at the rate of the bundles that amount can pay for. An amount equal to a bundle's price is that bundle.
     */
    public function requestTopUp(Clinic $clinic, float $amount): PlatformInvoice
    {
        $amount = round($amount, 2);
        if ($amount < SmsBundle::MIN_TOP_UP || $amount > SmsBundle::MAX_TOP_UP) {
            throw ValidationException::withMessages(['topUpAmount' => sprintf('Enter an amount between GHS %s and GHS %s.',
                number_format(SmsBundle::MIN_TOP_UP), number_format(SmsBundle::MAX_TOP_UP))]);
        }

        $quote = SmsBundle::quoteFor($amount);
        abort_unless($quote && $quote['credits'] > 0, 422, 'SMS credits are not on sale right now.');

        if (abs($quote['tier']['price'] - $amount) < 0.005) {
            return $this->requestBundle($clinic, SmsBundle::findOrFail($quote['tier']['id']));
        }

        return $this->issueInvoice($clinic, $amount, 'GHS', $quote['credits'], null, sprintf('SMS top-up: GHS %s at %s per SMS (%s credits)',
            number_format($amount, 2), number_format($quote['rate'], 3), number_format($quote['credits'])));
    }

    private function issueInvoice(Clinic $clinic, float $subtotal, string $currency, int $credits, ?int $bundleId, string $notes): PlatformInvoice
    {
        $subscription = $clinic->currentSubscription;
        $tax = round($subtotal * ((float) ($subscription?->pricing_snapshot['tax_rate'] ?? 0) / 100), 2);

        $invoice = PlatformInvoice::create([
            'number'                 => 'SMS-' . now()->format('YmHis') . '-' . Str::upper(Str::random(4)),
            'clinic_id'              => $clinic->id,
            'clinic_subscription_id' => $subscription?->id,
            'period_start'           => now()->toDateString(),
            'period_end'             => now()->toDateString(),
            'due_date'               => now()->addDays(7)->toDateString(),
            'subtotal'               => $subtotal,
            'tax'                    => $tax,
            'total'                  => $subtotal + $tax,
            'amount_paid'            => 0,
            'currency'               => $currency,
            'status'                 => 'unpaid',
            'source'                 => 'sms_bundle',
            'sms_bundle_id'          => $bundleId,
            'sms_credits'            => $credits,
            'notes'                  => $notes,
        ]);

        app(BillingNotificationService::class)->invoiceIssued($invoice->load(['clinic', 'subscription']));
        app(\App\Services\PlatformRequestAlerts::class)->smsBundleOrdered($invoice);

        return $invoice;
    }

    /** A clinic withdraws a bundle request it has not paid anything towards. */
    public function cancelRequest(PlatformInvoice $invoice, int $clinicId): void
    {
        abort_unless($invoice->clinic_id === $clinicId && $invoice->source === 'sms_bundle', 404);
        abort_unless($invoice->status === 'unpaid' && (float) $invoice->amount_paid === 0.0, 422, 'Only unpaid SMS requests can be cancelled.');

        $invoice->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => 'Cancelled by clinic before payment']);
    }

    /** Credits clinics have paid for but not yet used: what the platform gateway account must be able to cover. */
    public function outstandingCredits(): int
    {
        return (int) SmsWallet::where('balance', '>', 0)->sum('balance');
    }

    private function lockedWallet(int $clinicId): SmsWallet
    {
        SmsWallet::firstOrCreate(['clinic_id' => $clinicId]);

        return SmsWallet::where('clinic_id', $clinicId)->lockForUpdate()->first();
    }

    private function record(SmsWallet $wallet, string $type, int $credits, array $attributes): SmsCreditTransaction
    {
        return SmsCreditTransaction::create($attributes + [
            'clinic_id'     => $wallet->clinic_id,
            'type'          => $type,
            'credits'       => $credits,
            'balance_after' => $wallet->balance,
            'user_id'       => auth()->id(),
        ]);
    }

    private function notifyClinic(string $type, string $title, string $body): void
    {
        try {
            NotificationService::sendToRoles(['Super Admin'], $type, $title, $body, 'fas fa-sms', 'text-warning',
                route('admin.sms-settings', [], absolute: false));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
