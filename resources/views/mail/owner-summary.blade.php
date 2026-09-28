@php
    $s = $summary;
    $t = $s['total'];
    $money = fn ($amount) => $s['currency'] . ' ' . number_format((float) $amount, 2);
    // Up is good for sales and money in, bad for expenses.
    $trend = function (?float $change, bool $upIsGood = true) use ($s) {
        if ($change === null) return '<span style="color:#98a2b3">No figures for ' . e($s['compareWith']) . '</span>';
        if (abs($change) < 0.05) return '<span style="color:#667085">Same as ' . e($s['compareWith']) . '</span>';
        $good = ($change > 0) === $upIsGood;
        return '<span style="color:' . ($good ? '#15803d' : '#b42318') . '">' . ($change > 0 ? '&#9650; ' : '&#9660; ') . number_format(abs($change), 1) . '%</span> <span style="color:#98a2b3">vs ' . e($s['compareWith']) . '</span>';
    };
    $quiet = $t['sales'] == 0 && $t['received'] == 0 && $t['expenses'] == 0;
@endphp
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $s['title'] }} - {{ $s['clinic'] }}</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#172033">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:28px 12px">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border:1px solid #dbe3ed;border-radius:10px;overflow:hidden">

  <tr>
    <td style="background:#0d1b2e;padding:22px 28px">
      <p style="margin:0;font-size:19px;font-weight:bold;color:#fff">{{ $s['clinic'] }}</p>
      <p style="margin:6px 0 0;font-size:13px;color:#93c5fd">{{ $s['title'] }} &middot; {{ $s['label'] }}</p>
    </td>
  </tr>

  @if($quiet)
  <tr>
    <td style="padding:26px 28px 6px">
      <p style="margin:0;font-size:15px;line-height:1.6">No sales, payments or expenses were recorded for {{ $s['label'] }}.</p>
      <p style="margin:8px 0 0;font-size:13px;color:#667085">If the clinic was open, check that staff are recording sales in the system.</p>
    </td>
  </tr>
  @else
  {{-- The three headline figures --}}
  <tr>
    <td style="padding:24px 28px 4px">
      <table width="100%" cellpadding="0" cellspacing="0">
        @foreach([
            ['Sales', $t['sales'], $s['change']['sales'], true, '#2563eb'],
            ['Money received', $t['received'], $s['change']['received'], true, '#16a34a'],
            ['Expenses', $t['expenses'], $s['change']['expenses'], false, '#d97706'],
        ] as [$label, $amount, $change, $upIsGood, $colour])
        <tr>
          <td style="padding:0 0 10px">
            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ec;border-left:4px solid {{ $colour }};border-radius:6px">
              <tr>
                <td style="padding:12px 14px">
                  <p style="margin:0;font-size:12px;color:#667085;text-transform:uppercase;letter-spacing:.04em">{{ $label }}</p>
                  <p style="margin:3px 0 0;font-size:22px;font-weight:bold">{{ $money($amount) }}</p>
                  <p style="margin:3px 0 0;font-size:12px">{!! $trend($change, $upIsGood) !!}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endforeach
      </table>
    </td>
  </tr>

  {{-- The rest, as a statement --}}
  <tr>
    <td style="padding:6px 28px 4px">
      <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
        @php
            $lines = [];
            if ($t['hasClinic'] && $t['hasOptical']) {
                $lines[] = ['Clinic sales', $money($t['clinicSales'])];
                $lines[] = ['Optical sales', $money($t['opticalSales'])];
            }
            $lines[] = ['Transactions', number_format($t['transactions'])];
            foreach ($t['byMethod'] as $method => $amount) $lines[] = ['Received by ' . $method, $money($amount)];
            if ($t['refunds'] > 0) $lines[] = ['Refunds paid out', '-' . $money($t['refunds'])];
            $lines[] = ['Expenses', '-' . $money($t['expenses'])];
        @endphp
        @foreach($lines as [$label, $value])
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #f0f2f5;color:#475467">{{ $label }}</td>
          <td style="padding:8px 0 8px 12px;border-bottom:1px solid #f0f2f5;text-align:right;white-space:nowrap">{{ $value }}</td>
        </tr>
        @endforeach
        <tr>
          <td style="padding:10px 0;font-weight:bold">Money left after refunds and expenses</td>
          <td style="padding:10px 0 10px 12px;text-align:right;white-space:nowrap;font-weight:bold;color:{{ $t['net'] < 0 ? '#b42318' : '#15803d' }}">{{ $money($t['net']) }}</td>
        </tr>
      </table>
    </td>
  </tr>
  @endif

  @if($t['owed'] > 0)
  <tr>
    <td style="padding:8px 28px 4px">
      <p style="margin:0;padding:12px 14px;background:#fffaeb;border:1px solid #fedf89;border-radius:6px;font-size:14px">
        Customers still owe <b>{{ $money($t['owed']) }}</b> in total, as of this morning.
      </p>
    </td>
  </tr>
  @endif

  @php
      $watch = [];
      if (!empty($s['longView'])) {
          if ($t['owed90'] > 0) $watch[] = ['Owed for more than 90 days', $money($t['owed90']), 'The debts least likely to be paid. Worth a follow-up call.'];
          if ($t['discounts'] > 0) $watch[] = ['Discounts given', $money($t['discounts']), $t['sales'] > 0 ? number_format($t['discounts'] / ($t['sales'] + $t['discounts']) * 100, 1) . '% of sales before discounts' : null];
          if ($t['refunds'] > 0) $watch[] = ['Refunds paid out', $money($t['refunds']), null];
          if ($t['claimsWaiting'] > 0) $watch[] = ['Insurance claims waiting over 30 days', $t['claimsWaiting'] . ' · ' . $money($t['claimsWaitingAmount']), null];
          if ($t['claimsRejected'] > 0) $watch[] = ['Insurance claims rejected', $t['claimsRejected'] . ' · ' . $money($t['claimsRejectedAmount']), null];
      }
  @endphp
  @if($watch)
  <tr>
    <td style="padding:18px 28px 4px">
      <p style="margin:0 0 8px;font-size:13px;font-weight:bold;color:#344054;text-transform:uppercase;letter-spacing:.04em">Worth a look</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border:1px solid #e4e7ec;border-radius:6px">
        @foreach($watch as [$label, $value, $note])
        <tr>
          <td style="padding:9px 12px;{{ $loop->first ? '' : 'border-top:1px solid #f0f2f5;' }}">
            {{ $label }}
            @if($note)<div style="font-size:12px;color:#98a2b3;margin-top:2px">{{ $note }}</div>@endif
          </td>
          <td style="padding:9px 12px;text-align:right;font-weight:bold;white-space:nowrap;{{ $loop->first ? '' : 'border-top:1px solid #f0f2f5;' }}">{{ $value }}</td>
        </tr>
        @endforeach
      </table>
    </td>
  </tr>
  @endif

  @if($s['branches'])
  <tr>
    <td style="padding:18px 28px 4px">
      <p style="margin:0 0 8px;font-size:13px;font-weight:bold;color:#344054;text-transform:uppercase;letter-spacing:.04em">By branch</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border:1px solid #e4e7ec;border-radius:6px">
        <tr style="background:#f9fafb;color:#667085">
          <td style="padding:8px 10px">Branch</td>
          <td style="padding:8px 10px;text-align:right">Sales</td>
          <td style="padding:8px 10px;text-align:right">Received</td>
          <td style="padding:8px 10px;text-align:right">Expenses</td>
        </tr>
        @foreach($s['branches'] as $name => $row)
        <tr>
          <td style="padding:8px 10px;border-top:1px solid #f0f2f5">{{ $name }}</td>
          <td style="padding:8px 10px;border-top:1px solid #f0f2f5;text-align:right">{{ number_format($row['sales'], 2) }}</td>
          <td style="padding:8px 10px;border-top:1px solid #f0f2f5;text-align:right">{{ number_format($row['received'], 2) }}</td>
          <td style="padding:8px 10px;border-top:1px solid #f0f2f5;text-align:right">{{ number_format($row['expenses'], 2) }}</td>
        </tr>
        @endforeach
      </table>
    </td>
  </tr>
  @endif

  <tr>
    <td style="padding:22px 28px 26px">
      <a href="{{ $s['url'] }}" style="display:inline-block;background:#1688c4;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">Open full reports</a>
    </td>
  </tr>
  <tr>
    <td style="padding:14px 28px;background:#f9fafb;border-top:1px solid #eef0f3;font-size:12px;color:#98a2b3;line-height:1.5">
      Sales count when made; money counts when received. Sent to the clinic owner by {{ config('app.name') }}; change the owner email under Subscription &rarr; Billing profile.
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
