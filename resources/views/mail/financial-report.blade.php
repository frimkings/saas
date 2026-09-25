<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $report['subject'] }}</title>
<style>
  body { margin:0; padding:0; background:#f4f6f9; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#333; }
  a    { color:#2563eb; }
</style>
</head>
<body>
@php($currency = currency())
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:32px 0">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">

  {{-- Header --}}
  <tr>
    <td style="background:#1a3a5c;padding:28px 36px;text-align:center">
      <p style="margin:0;font-size:22px;font-weight:bold;color:#fff">
        {{ $report['clinic']->clinic_name ?? 'Eye Clinic' }}
      </p>
      <p style="margin:6px 0 0;font-size:13px;color:#93c5fd;letter-spacing:.04em;text-transform:uppercase">
        {{ $report['period_label'] }}@if(!empty($report['lines'])) &middot; Clinic &amp; Optical @endif
      </p>
      <p style="margin:4px 0 0;font-size:12px;color:#7dd3fc">
        @if(str_contains(strtolower($report['period_label']), 'weekly'))
          {{ $report['start']->format('d M Y') }} &mdash; {{ $report['end']->format('d M Y') }}
        @else
          {{ $report['start']->format('d F Y') }}
        @endif
      </p>
    </td>
  </tr>

  {{-- Key metric cards --}}
  <tr>
    <td style="padding:28px 36px 0">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          {{-- Gross Revenue --}}
          <td width="33%" style="padding-right:8px">
            <table width="100%" cellpadding="16" style="background:#eff6ff;border-radius:6px;border-left:4px solid #2563eb">
              <tr><td>
                <p style="margin:0;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">Gross Revenue</p>
                <p style="margin:4px 0 0;font-size:22px;font-weight:bold;color:#1e40af">
                  {{ $currency }}{{ number_format($report['gross_revenue'], 2) }}
                </p>
              </td></tr>
            </table>
          </td>
          {{-- Net Revenue --}}
          <td width="33%" style="padding:0 4px">
            <table width="100%" cellpadding="16" style="background:#f0fdf4;border-radius:6px;border-left:4px solid #16a34a">
              <tr><td>
                <p style="margin:0;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">Net Revenue</p>
                <p style="margin:4px 0 0;font-size:22px;font-weight:bold;color:#15803d">
                  {{ $currency }}{{ number_format($report['net_revenue'], 2) }}
                </p>
              </td></tr>
            </table>
          </td>
          {{-- Transactions --}}
          <td width="33%" style="padding-left:8px">
            <table width="100%" cellpadding="16" style="background:#faf5ff;border-radius:6px;border-left:4px solid #7c3aed">
              <tr><td>
                <p style="margin:0;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">Transactions</p>
                <p style="margin:4px 0 0;font-size:22px;font-weight:bold;color:#6d28d9">
                  {{ number_format($report['total_transactions']) }}
                </p>
              </td></tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  {{-- Breakdown table --}}
  <tr>
    <td style="padding:24px 36px 0">
      <p style="margin:0 0 10px;font-size:13px;font-weight:bold;color:#374151;text-transform:uppercase;letter-spacing:.05em">Breakdown</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden">

        <tr style="background:#f9fafb">
          <td style="padding:10px 16px;font-size:12px;font-weight:bold;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb" width="70%">Item</td>
          <td style="padding:10px 16px;font-size:12px;font-weight:bold;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb;text-align:right">Value</td>
        </tr>

        <tr>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151">
            <span style="display:inline-block;width:10px;height:10px;background:#16a34a;border-radius:50%;margin-right:8px"></span>
            Fully Paid Transactions
          </td>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:bold;color:#15803d">
            {{ $report['paid_count'] }}
          </td>
        </tr>

        <tr style="background:#fafafa">
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151">
            <span style="display:inline-block;width:10px;height:10px;background:#f59e0b;border-radius:50%;margin-right:8px"></span>
            Partial Payments
          </td>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:bold;color:#d97706">
            {{ $report['partial_count'] }}
          </td>
        </tr>

        <tr>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151">
            <span style="display:inline-block;width:10px;height:10px;background:#9ca3af;border-radius:50%;margin-right:8px"></span>
            Pending / Unpaid
          </td>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;text-align:right;color:#6b7280">
            {{ $report['pending_count'] }}
          </td>
        </tr>

        <tr style="background:#fafafa">
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151">
            Outstanding Balance (uncollected)
          </td>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;text-align:right;color:#b45309">
            {{ $currency }}{{ number_format($report['outstanding'], 2) }}
          </td>
        </tr>

        <tr>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151">
            Discounts Given
          </td>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;text-align:right;color:#dc2626">
            &minus;{{ $currency }}{{ number_format($report['total_discounts'], 2) }}
          </td>
        </tr>

        <tr style="background:#fafafa">
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151">
            Refunds Processed ({{ $report['refund_count'] }})
          </td>
          <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;text-align:right;color:#dc2626">
            &minus;{{ $currency }}{{ number_format($report['refund_total'], 2) }}
          </td>
        </tr>

        <tr style="background:#f0fdf4">
          <td style="padding:14px 16px;font-weight:bold;color:#166534;font-size:15px">
            Net Revenue
          </td>
          <td style="padding:14px 16px;text-align:right;font-weight:bold;color:#15803d;font-size:17px">
            {{ $currency }}{{ number_format($report['net_revenue'], 2) }}
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- By business (subscribers running both clinic and optical) --}}
  @if(!empty($report['lines']))
  <tr>
    <td style="padding:24px 36px 0">
      <p style="margin:0 0 10px;font-size:13px;font-weight:bold;color:#374151;text-transform:uppercase;letter-spacing:.05em">By Business</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden">
        <tr style="background:#f9fafb">
          <td style="padding:10px 16px;font-size:12px;font-weight:bold;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb" width="40%">Item</td>
          @foreach($report['lines'] as $line => $figures)
            <td style="padding:10px 16px;font-size:12px;font-weight:bold;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb;text-align:right">{{ ucfirst($line) }}</td>
          @endforeach
        </tr>
        @foreach(['total_transactions' => 'Transactions', 'gross_revenue' => 'Gross Revenue', 'refund_total' => 'Refunds', 'net_revenue' => 'Net Revenue', 'outstanding' => 'Outstanding'] as $key => $label)
          <tr @if($loop->even) style="background:#fafafa" @endif>
            <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;color:#374151">{{ $label }}</td>
            @foreach($report['lines'] as $figures)
              <td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;text-align:right;color:#374151">
                @if($key === 'total_transactions'){{ number_format($figures[$key]) }}@else{{ $currency }}{{ number_format($figures[$key], 2) }}@endif
              </td>
            @endforeach
          </tr>
        @endforeach
      </table>
    </td>
  </tr>
  @endif

  {{-- Note --}}
  <tr>
    <td style="padding:20px 36px">
      <table width="100%" cellpadding="12" style="background:#fffbeb;border-radius:6px;border:1px solid #fde68a">
        <tr><td style="font-size:12px;color:#92400e">
          <strong>Note:</strong>
          Gross Revenue = total amount collected from non-refunded sales.
          Net Revenue = Gross Revenue minus refunds processed in this period.
          Outstanding = unpaid or partially paid balances.
        </td></tr>
      </table>
    </td>
  </tr>

  {{-- Footer --}}
  <tr>
    <td style="background:#f9fafb;padding:20px 36px;text-align:center;border-top:1px solid #e5e7eb">
      <p style="margin:0;font-size:11px;color:#9ca3af">
        This report was automatically generated by {{ $report['clinic']->clinic_name ?? 'Eye Clinic' }} system
        on {{ now()->format('d M Y \a\t H:i') }}.
      </p>
      <p style="margin:6px 0 0;font-size:11px;color:#d1d5db">
        {{ $report['clinic']->clinic_address ?? '' }}
        @if($report['clinic']->clinic_contact)
          &nbsp;&middot;&nbsp; {{ $report['clinic']->clinic_contact }}
        @endif
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
