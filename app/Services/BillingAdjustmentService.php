<?php
namespace App\Services;
use App\Models\{PlatformCreditNote,PlatformInvoice,PlatformPayment,PlatformPaymentRefund};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class BillingAdjustmentService
{
 /**
  * Undoes a payment recorded by mistake (wrong clinic, wrong amount). Unlike a refund no money goes
  * back: the payment stays on record marked reversed, the invoice is owed again, and if that payment
  * renewed the subscription its dates go back to what they were before.
  */
 public function reverse(PlatformPayment $payment, string $reason, ?int $operator): PlatformPayment
 {
     return DB::transaction(function () use ($payment, $reason, $operator) {
         $payment = PlatformPayment::lockForUpdate()->findOrFail($payment->id);
         if ($payment->status !== 'confirmed') throw ValidationException::withMessages(['reversal' => 'This payment was already reversed.']);
         if ($payment->refunds()->exists()) throw ValidationException::withMessages(['reversal' => 'Part of this payment was refunded, so it can\'t be reversed. Refund the rest instead.']);
         $invoice = PlatformInvoice::lockForUpdate()->findOrFail($payment->platform_invoice_id);
         $wasPaid = $invoice->status === 'paid';
         if ($wasPaid && $invoice->source === 'sms_bundle') throw ValidationException::withMessages(['reversal' => 'The SMS credits from this bundle were already given. Refund it instead, and adjust the clinic\'s SMS credits.']);

         $subscription = null;
         if ($wasPaid && $invoice->source === 'renewal' && $invoice->clinic_subscription_id) {
             $subscription = \App\Models\ClinicSubscription::lockForUpdate()->find($invoice->clinic_subscription_id);
             // Only the latest renewal can be undone: a later paid period would be left hanging.
             if ($subscription && $invoice->period_end && $subscription->current_period_ends_at?->copy()->startOfDay()->gt($invoice->period_end->copy()->endOfDay())) {
                 throw ValidationException::withMessages(['reversal' => 'A later renewal has been paid since this one. Reverse that payment first.']);
             }
         }

         $payment->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $operator, 'reversal_reason' => $reason,
             // Freed so the real payment, recorded again, is not taken for this one.
             'idempotency_key' => null]);
         $invoice->amount_paid = round(max(0, (float) $invoice->amount_paid - (float) $payment->amount), 2);
         $invoice->status = $invoice->balance() <= 0 ? 'paid' : ((float) $invoice->amount_paid > 0 ? 'partial' : 'unpaid');
         if ($invoice->status !== 'paid') $invoice->paid_at = null;

         if ($subscription && $invoice->status !== 'paid') {
             $before = $invoice->renewed_from;
             if (is_array($before) && (int) ($before['subscription_id'] ?? 0) === $subscription->id) {
                 foreach (['status', 'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at', 'grace_ends_at', 'restricted_at', 'suspended_at', 'status_changed_at'] as $field) {
                     $subscription->setAttribute($field, $before[$field] ?? null);
                 }
                 if (! empty($before['invoice_period_start'])) {
                     $invoice->period_start = $before['invoice_period_start'];
                     $invoice->period_end = $before['invoice_period_end'];
                 }
             } else {
                 // Renewed before renewals kept their earlier dates: the period ends where this invoice's began.
                 $subscription->current_period_ends_at = $invoice->period_start->copy()->startOfDay()->subSecond();
             }
             $invoice->renewed_from = null;
             $subscription->save();
             app(SubscriptionLifecycleService::class)->evaluate($subscription);
         }
         $invoice->save();

         return $payment->refresh();
     });
 }

 public function credit(PlatformInvoice $invoice,float $amount,string $reason,?int $operator):PlatformCreditNote{return DB::transaction(function()use($invoice,$amount,$reason,$operator){$invoice=PlatformInvoice::lockForUpdate()->findOrFail($invoice->id);if($invoice->voided_at||$amount<=0||$amount>$invoice->balance())throw ValidationException::withMessages(['adjustmentAmount'=>'Credit must be positive and cannot exceed the invoice balance.']);$note=PlatformCreditNote::create(['number'=>'CN-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),'clinic_id'=>$invoice->clinic_id,'platform_invoice_id'=>$invoice->id,'amount'=>$amount,'currency'=>$invoice->currency,'reason'=>$reason,'issued_by'=>$operator,'issued_at'=>now()]);$credited=round((float)$invoice->credited_amount+$amount,2);$invoice->credited_amount=$credited;$invoice->status=$invoice->balance()<=0?'credited':((float)$invoice->amount_paid>0?'partial':'unpaid');$invoice->save();return $note;});}
 public function refund(PlatformPayment $payment,float $amount,string $method,string $reason,?string $reference,?int $operator):PlatformPaymentRefund{return DB::transaction(function()use($payment,$amount,$method,$reason,$reference,$operator){$payment=PlatformPayment::with('invoice')->lockForUpdate()->findOrFail($payment->id);$refunded=(float)$payment->refunds()->sum('amount');if($amount<=0||$amount>(float)$payment->amount-$refunded)throw ValidationException::withMessages(['adjustmentAmount'=>'Refund cannot exceed the unrefunded payment amount.']);$invoice=PlatformInvoice::lockForUpdate()->findOrFail($payment->platform_invoice_id);$refund=PlatformPaymentRefund::create(['number'=>'RF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),'clinic_id'=>$invoice->clinic_id,'platform_invoice_id'=>$invoice->id,'platform_payment_id'=>$payment->id,'amount'=>$amount,'currency'=>$invoice->currency,'method'=>$method,'reference'=>$reference,'reason'=>$reason,'refunded_by'=>$operator,'refunded_at'=>now()]);$invoice->refunded_amount=round((float)$invoice->refunded_amount+$amount,2);$invoice->status=$invoice->balance()+$amount>0?'partial':'paid';$invoice->save();return $refund;});}
 public function void(PlatformInvoice $invoice,string $reason,?int $operator):PlatformInvoice{return DB::transaction(function()use($invoice,$reason,$operator){$invoice=PlatformInvoice::lockForUpdate()->findOrFail($invoice->id);if((float)$invoice->amount_paid>0||(float)$invoice->credited_amount>0)throw ValidationException::withMessages(['adjustmentReason'=>'Paid or credited invoices cannot be voided; issue a refund or credit note instead.']);$invoice->update(['status'=>'void','voided_at'=>now(),'void_reason'=>$reason,'voided_by'=>$operator]);return $invoice;});}
}
