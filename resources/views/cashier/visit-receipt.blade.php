<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $settled ? 'Visit Receipt' : 'Part Payment' }} - {{ $visit->visit_number }}</title>
    @include('cashier.partials.thermal-styles')
</head>
<body>
@php
    $currency = currency() . ' ';
@endphp

    <div class="center">
        @if($clinicSettings->logoDataUri())
            <img src="{{ $clinicSettings->logoDataUri() }}" class="receipt-logo" alt="Clinic Logo">
        @endif

        <div class="clinic-name">{{ $clinicSettings->clinic_name ?? 'CLINIC' }}</div>
        @if(!empty($clinicSettings->clinic_address))
            <div class="small">{{ $clinicSettings->clinic_address }}</div>
        @endif
        @if(!empty($clinicSettings->clinic_contact))
            <div class="small">Tel: {{ $clinicSettings->clinic_contact }}</div>
        @endif
        @if(!empty($clinicSettings->clinic_email))
            <div class="small">{{ $clinicSettings->clinic_email }}</div>
        @endif

        <div class="divider"></div>
        <div class="small"><strong>VISIT: {{ $visit->visit_number }}</strong></div>
        <div class="small">{{ $visit->opened_on->format('M d, Y') }}</div>
        @if($settled)
            <div class="small"><strong>VISIT RECEIPT · PAID IN FULL</strong></div>
        @else
            <div class="small"><strong>PART PAYMENT — BALANCE DUE</strong></div>
        @endif
    </div>

    <div class="section">
        <div class="label">Patient</div>
        <div>{{ $patient?->name }}</div>
        <div class="small">Contact: {{ $patient?->contact ?? 'N/A' }}</div>
        <div class="small">ID: {{ $patient?->pxnumber ?? 'N/A' }}</div>
    </div>
    <div class="divider"></div>

    @forelse($sections as $title => $lines)
        <div class="label">{{ strtoupper($title) }}</div>
        <table>
            <tbody>
                @foreach($lines as $line)
                    <tr>
                        <td class="col-item">
                            {{ \Illuminate\Support\Str::limit($line['name'], 19) }}
                            @if($line['on_hold'])<div class="small muted">(on hold)</div>@endif
                            @if($line['refunded'] > 0)<div class="small muted">({{ $line['refunded'] }} refunded)</div>@endif
                        </td>
                        <td class="col-qty">{{ $line['quantity'] }}</td>
                        <td class="col-price">{{ $currency }}{{ number_format($line['price'], 2) }}</td>
                        <td class="col-total">{{ $currency }}{{ number_format($line['subtotal'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="divider"></div>
    @empty
        <div class="small muted center">No items on this visit.</div>
        <div class="divider"></div>
    @endforelse

    @if($discount > 0)
        <div class="money-row">
            <span class="left">DISCOUNT</span>
            <span class="right">-{{ $currency }}{{ number_format($discount, 2) }}</span>
        </div>
    @endif

    <div class="money-row grand-total">
        <span class="left">VISIT TOTAL</span>
        <span class="right">{{ $currency }}{{ number_format($total, 2) }}</span>
    </div>

    @if($insurer > 0)
        <div class="money-row">
            <span class="left">BILLED TO {{ strtoupper($insurerName ?? 'INSURER') }}</span>
            <span class="right">{{ $currency }}{{ number_format($insurer, 2) }}</span>
        </div>
        <div class="money-row">
            <span class="left">PATIENT PAYS</span>
            <span class="right">{{ $currency }}{{ number_format(max(0, $total - $insurer), 2) }}</span>
        </div>
    @endif

    @foreach($payments as $payment)
        <div class="money-row">
            <span class="left">PAID ({{ strtoupper(\App\Support\PaymentMethods::label($payment['method'], \App\Support\PaymentMethods::CLINIC)) }}) {{ $payment['at']->format('d/m H:i') }}</span>
            <span class="right">{{ $currency }}{{ number_format($payment['amount'], 2) }}</span>
        </div>
    @endforeach

    @if($payments->count() > 1 || $payments->isEmpty())
        <div class="money-row">
            <span class="left">TOTAL PAID</span>
            <span class="right">{{ $currency }}{{ number_format($paid, 2) }}</span>
        </div>
    @endif

    @if($refunded > 0)
        <div class="money-row">
            <span class="left">REFUNDED</span>
            <span class="right">-{{ $currency }}{{ number_format($refunded, 2) }}</span>
        </div>
    @endif

    @if($balance > 0)
        <div class="money-row grand-total">
            <span class="left">BALANCE DUE</span>
            <span class="right">{{ $currency }}{{ number_format($balance, 2) }}</span>
        </div>
    @endif

    <div class="divider"></div>
    <div class="small muted">Transactions: {{ implode(', ', $transactions) }}</div>

    <div class="footer">
        <div>Thank you for your visit!</div>
        <div>Please keep this receipt for your records.</div>
        <div class="divider"></div>
        @if($servedBy)<div>Served by: {{ implode(', ', $servedBy) }}</div>@endif
        <div>{{ now()->format('M d, Y h:i A') }}</div>
    </div>
</body>
</html>
