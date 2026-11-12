<?php

namespace App\Services\Scans;

use App\Models\AuditTrail;
use App\Models\DiagnosticScan;
use App\Models\PaymentTransaction;
use App\Models\SaleItem;
use App\Models\Sales;
use App\Models\ScanOrder;
use App\Models\ScanOrderItem;
use App\Models\ScanPartnerClinic;
use App\Models\ScanPartnerDoctor;
use App\Services\Visits\PatientVisits;
use App\Support\PaymentMethods;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Scan orders from registration to reported: create, check out (a normal clinic sale the
 * patient pays, in full or in part), then mark scanned and reported. Referred patients always
 * pay; the referrer's commission is worked out from the paid sale (phase 3).
 */
class ScanOrderService
{
    public function __construct(private ScanCatalogue $catalogue) {}

    /**
     * @param array{patient_id:int, source:string, scan_partner_clinic_id?:?int, scan_partner_doctor_id?:?int,
     *              referrer_note?:?string, share_consent?:bool, notes?:?string, ordered_by?:?int, consultation_id?:?int,
     *              items: list<array{diagnostic_scan_id:int, eye?:?string}>} $data
     */
    public function create(array $data): ScanOrder
    {
        $source = $data['source'] ?? '';
        if (! array_key_exists($source, ScanOrder::SOURCES)) {
            throw ValidationException::withMessages(['source' => 'Choose who referred the patient.']);
        }
        $partner = null;
        $doctorId = null;
        if ($source === 'partner') {
            $partner = ScanPartnerClinic::where('is_active', true)->find($data['scan_partner_clinic_id'] ?? 0);
            if (! $partner) throw ValidationException::withMessages(['partnerId' => 'Choose the partner clinic that referred the patient.']);
            if (! empty($data['scan_partner_doctor_id'])) {
                $doctorId = ScanPartnerDoctor::where('scan_partner_clinic_id', $partner->id)->find($data['scan_partner_doctor_id'])?->id;
                if (! $doctorId) throw ValidationException::withMessages(['partnerDoctorId' => 'That doctor is not listed for this partner.']);
            }
        }
        if ($source === 'internal' && empty($data['ordered_by'])) {
            throw ValidationException::withMessages(['source' => 'An internal order needs the doctor who ordered it.']);
        }

        $lines = $this->lines($data['items'] ?? []);

        return DB::transaction(function () use ($data, $source, $partner, $doctorId, $lines) {
            $consent = $source === 'partner' && ! empty($data['share_consent']);
            $order = ScanOrder::create([
                'order_number' => ScanOrder::nextNumber(),
                'patient_id' => $data['patient_id'],
                'source' => $source,
                'scan_partner_clinic_id' => $partner?->id,
                'scan_partner_doctor_id' => $doctorId,
                'referrer_note' => trim((string) ($data['referrer_note'] ?? '')) ?: null,
                'ordered_by' => $source === 'internal' ? $data['ordered_by'] : null,
                'consultation_id' => $source === 'internal' ? ($data['consultation_id'] ?? null) : null,
                'status' => 'registered',
                'total_amount' => round(array_sum(array_column($lines, 'line_total')), 2),
                'share_consent' => $consent,
                'consent_by' => $consent ? Auth::id() : null,
                'consent_at' => $consent ? now() : null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_by' => Auth::id(),
            ]);
            foreach ($lines as $line) {
                $order->items()->create($line);
            }
            AuditTrail::record('scans.order_created', "Scan order {$order->order_number}: ".$order->referrerLabel().' — '.$order->items->map->label()->implode(', ')
                .($consent ? ' (patient agreed to share results)' : ''), $order, [], ['total' => (float) $order->total_amount], $order->patient_id);

            return $order;
        });
    }

    /** Priced lines from the catalogue: scans priced per eye charge each eye. */
    private function lines(array $items): array
    {
        if ($items === []) throw ValidationException::withMessages(['items' => 'Add at least one scan.']);
        $scans = DiagnosticScan::where('is_active', true)->whereIn('id', array_column($items, 'diagnostic_scan_id'))->get()->keyBy('id');

        return array_map(function ($item) use ($scans) {
            $scan = $scans[(int) ($item['diagnostic_scan_id'] ?? 0)] ?? null;
            if (! $scan) throw ValidationException::withMessages(['items' => 'A chosen scan is no longer offered. Remove it and try again.']);
            $eye = $scan->per_eye ? ($item['eye'] ?? 'OU') : null;
            if ($eye !== null && ! array_key_exists($eye, ScanOrderItem::EYES)) {
                throw ValidationException::withMessages(['items' => "Choose the eye for {$scan->name}."]);
            }
            $quantity = $eye === 'OU' ? 2 : 1;

            return ['diagnostic_scan_id' => $scan->id, 'scan_name' => $scan->name, 'eye' => $eye, 'quantity' => $quantity,
                'unit_price' => $scan->price, 'line_total' => round((float) $scan->price * $quantity, 2)];
        }, array_values($items));
    }

    /**
     * Bill the order and take the patient's payment: the whole amount, or part of it with the
     * rest on Outstanding Balances.
     *
     * @param list<array{method:string, amount:float|string}> $payments
     */
    public function checkout(ScanOrder $order, array $payments): Sales
    {
        if ($order->status === 'cancelled') throw ValidationException::withMessages(['payments' => 'This order was cancelled.']);
        if ($order->sale_id) throw ValidationException::withMessages(['payments' => 'This order is already billed.']);

        $allowed = PaymentMethods::keys(PaymentMethods::CLINIC);
        $payments = collect($payments)->map(fn ($p) => ['method' => strtolower(trim((string) ($p['method'] ?? ''))), 'amount' => round((float) ($p['amount'] ?? 0), 2)])
            ->filter(fn ($p) => $p['amount'] > 0)->values();
        if ($payments->contains(fn ($p) => ! in_array($p['method'], $allowed, true))) {
            throw ValidationException::withMessages(['payments' => 'Choose a payment method for each payment.']);
        }

        return DB::transaction(function () use ($order, $payments) {
            $order = ScanOrder::with('items.scan', 'patient')->lockForUpdate()->findOrFail($order->id);
            if ($order->sale_id) throw ValidationException::withMessages(['payments' => 'This order is already billed.']);

            $total = round((float) $order->items->sum('line_total'), 2);
            $paid = round((float) $payments->sum('amount'), 2);
            if ($total > 0 && $paid <= 0) throw ValidationException::withMessages(['payments' => 'Enter the amount the patient pays now.']);
            if ($paid - $total > 0.005) {
                throw ValidationException::withMessages(['payments' => 'Payments come to more than the bill of '.currency().' '.number_format($total, 2).'. Enter what is kept, not what was handed over.']);
            }
            $full = $total - $paid < 0.005;

            $sale = Sales::create([
                'business_line' => 'clinic',
                'user_id' => Auth::id(),
                'patient_id' => $order->patient_id,
                'consultation_id' => $order->consultation_id,
                'transaction_id' => now()->format('dmY').'-'.strtoupper(Str::random(8)),
                'total_amount' => $total,
                'amount_paid' => $paid,
                'insurer_amount' => 0,
                'payment_status' => $full ? 'paid' : 'partial',
                'bill_status' => $full ? 'finalized' : 'open',
                'finalized_at' => $full ? now() : null,
                'finalized_by' => $full ? Auth::id() : null,
                'expires_at' => $full ? null : now()->addDays(30),
                // Scans cost the clinic nothing per sale; a part-paid bill's profit is counted once settled, as at the till.
                'profit' => $full ? $total : 0,
            ]);

            foreach ($order->items as $item) {
                $product = $this->catalogue->productFor($item->scan);
                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id, 'product_id' => $product->id,
                    'prescribed_quantity' => $item->quantity, 'dispensed_quantity' => $item->quantity,
                    'selling_price' => $item->unit_price, 'unit_cost' => 0, 'subtotal' => $item->line_total, 'insurer_amount' => 0,
                    'eye' => $item->eye, 'notes' => 'Diagnostic scan '.$order->order_number,
                ]);
                $item->forceFill(['sale_item_id' => $saleItem->id])->save();
            }

            foreach ($payments as $payment) {
                PaymentTransaction::create(['sale_id' => $sale->id, 'amount' => $payment['amount'], 'payment_method' => $payment['method'],
                    'notes' => 'Scan order '.$order->order_number, 'collected_by' => Auth::id()]);
            }

            $order->forceFill(['sale_id' => $sale->id, 'total_amount' => $total, 'checked_out_at' => now()])->save();
            app(PatientVisits::class)->attach($sale);
            // The sale was saved before the order pointed at it: paid in full, it earns now.
            app(ScanCommissionService::class)->syncForSale($sale);

            AuditTrail::record('sale.created', "Scan sale {$sale->transaction_id} for {$order->order_number}: ".currency().' '.number_format($total, 2)
                .' | '.$payments->map(fn ($p) => PaymentMethods::label($p['method'], PaymentMethods::CLINIC).' '.number_format($p['amount'], 2))->implode(', ')
                .($full ? '' : ' | balance '.number_format($total - $paid, 2).' owed'), $sale, [], [], $order->patient_id);

            return $sale;
        });
    }

    public function markScanned(ScanOrder $order): void
    {
        $this->move($order, ['registered'], 'scanned', ['scanned_at' => now(), 'scanned_by' => Auth::id()],
            'Bill the order before the scan is done.', true);
    }

    public function markReported(ScanOrder $order): void
    {
        $this->move($order, ['scanned'], 'reported', ['reported_at' => now(), 'reported_by' => Auth::id()], 'Mark the scan as done first.');
    }

    /** Back one step, for a mistaken tap. */
    public function undo(ScanOrder $order): void
    {
        $back = ['scanned' => ['registered', ['scanned_at' => null, 'scanned_by' => null]], 'reported' => ['scanned', ['reported_at' => null, 'reported_by' => null]]];
        if (! isset($back[$order->status])) throw ValidationException::withMessages(['status' => 'There is no step to undo.']);
        [$to, $clear] = $back[$order->status];
        $from = $order->status;
        $order->forceFill(['status' => $to] + $clear)->save();
        AuditTrail::record('scans.order_status', "Scan order {$order->order_number}: {$from} undone, back to {$to}", $order, ['status' => $from], ['status' => $to], $order->patient_id);
    }

    /** Only before it is billed; after that the sale is refunded the usual way. */
    public function cancel(ScanOrder $order, string $reason): void
    {
        if ($order->sale_id) throw ValidationException::withMessages(['cancelReason' => 'This order is billed. Refund the sale from Sales Records instead.']);
        if ($order->status === 'cancelled') return;
        $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        AuditTrail::record('scans.order_cancelled', "Scan order {$order->order_number} cancelled: {$reason}", $order, ['status' => 'registered'], ['status' => 'cancelled'], $order->patient_id);
    }

    private function move(ScanOrder $order, array $from, string $to, array $stamps, string $notReady, bool $needsBill = false): void
    {
        if (! in_array($order->status, $from, true) || ($needsBill && ! $order->sale_id)) {
            throw ValidationException::withMessages(['status' => $order->status === 'cancelled' ? 'This order was cancelled.' : $notReady]);
        }
        $old = $order->status;
        $order->forceFill(['status' => $to] + $stamps)->save();
        AuditTrail::record('scans.order_status', "Scan order {$order->order_number}: {$old} → {$to}", $order, ['status' => $old], ['status' => $to], $order->patient_id);
    }
}
