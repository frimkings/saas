<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $heading }}</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#172033">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:28px 12px">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border:1px solid #dbe3ed;border-radius:10px;overflow:hidden">
  <tr>
    <td style="background:#0d1b2e;padding:20px 28px">
      <p style="margin:0;font-size:18px;font-weight:bold;color:#fff">{{ $clinicName }}</p>
    </td>
  </tr>
  <tr>
    <td style="padding:26px 28px 8px">
      <h2 style="margin:0 0 10px;font-size:20px">{{ $heading }}</h2>
      <p style="margin:0;line-height:1.6;color:#344054">{{ $intro }}</p>
    </td>
  </tr>
  @if($details)
  <tr>
    <td style="padding:14px 28px 4px">
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e7ec;border-radius:8px">
        @foreach($details as $label => $value)
        <tr>
          <td style="padding:10px 14px;font-size:13px;color:#667085;width:40%;{{ $loop->last ? '' : 'border-bottom:1px solid #f0f2f5;' }}">{{ $label }}</td>
          <td style="padding:10px 14px;font-size:14px;font-weight:bold;{{ $loop->last ? '' : 'border-bottom:1px solid #f0f2f5;' }}">{{ $value }}</td>
        </tr>
        @endforeach
      </table>
    </td>
  </tr>
  @endif
  <tr>
    <td style="padding:20px 28px 26px">
      <a href="{{ $buttonUrl }}" style="display:inline-block;background:#1688c4;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">{{ $buttonLabel }}</a>
      @if($footnote)<p style="margin:16px 0 0;font-size:13px;color:#667085;line-height:1.5">{{ $footnote }}</p>@endif
    </td>
  </tr>
  <tr>
    <td style="padding:14px 28px;background:#f9fafb;border-top:1px solid #eef0f3;font-size:12px;color:#98a2b3">
      @if($footer){{ $footer }}@else Sent to the clinic owner by {{ config('mail.from.name') }}. You receive this because this address is the clinic's owner email; change it under Subscription &rarr; Billing profile.@endif
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
