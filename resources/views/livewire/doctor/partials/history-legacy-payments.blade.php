{{-- Payments brought over from the clinic's previous software (App\Models\PatientLegacyPayment). Reference only: not bills or sales. --}}
<section class="history-surface">
    <div class="history-surface-heading"><div>
        <h3><i class="fas fa-receipt text-teal-700"></i> Payments in the Previous Software</h3>
        <p>What this patient paid before the clinic moved here, as recorded in the previous software. For reference only: these are not bills or sales here and are not in receipts, the cash book or finance reports.</p>
    </div></div>
    <div class="modern-table-wrap">
        <table class="modern-table">
            <thead><tr><th>Date</th><th style="text-align:right">Amount</th><th>Method</th><th>Reference</th><th>Invoice</th><th>Recorded by</th><th>Note</th></tr></thead>
            <tbody>
            @foreach($legacyPayments as $payment)
                <tr wire:key="legacy-payment-{{ $payment->id }}">
                    <td>{{ $payment->paid_at->format('d M Y H:i') }}</td>
                    <td style="text-align:right">{{ currency() }} {{ number_format($payment->amount, 2) }}</td>
                    <td>{{ $payment->payment_method ?? '—' }}</td>
                    <td>{{ $payment->reference ?? '—' }}</td>
                    <td>{{ $payment->invoice_number ?? '—' }}</td>
                    <td>{{ $payment->recorded_by ?? '—' }}</td>
                    <td>{{ $payment->notes }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><th>Total ({{ $legacyPayments->count() }})</th><th style="text-align:right">{{ currency() }} {{ number_format($legacyPayments->sum('amount'), 2) }}</th><th colspan="5"></th></tr></tfoot>
        </table>
    </div>
</section>
