<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $announcement->heading }}</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#172033">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:28px 12px">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border:1px solid #dbe3ed;border-radius:10px;overflow:hidden">
  <tr>
    <td style="background:#0d1b2e;padding:20px 28px">
      <p style="margin:0;font-size:18px;font-weight:bold;color:#fff">{{ config('mail.from.name') }}</p>
      <p style="margin:4px 0 0;font-size:12px;color:#9fb3c8">For {{ $clinicName }}</p>
    </td>
  </tr>
  <tr>
    <td style="padding:26px 28px 6px">
      <h2 style="margin:0 0 14px;font-size:20px">{{ $announcement->heading }}</h2>
      {!! \App\Services\Platform\Announcements::bodyHtml($announcement->body, $clinicName) !!}
    </td>
  </tr>
  @if($announcement->button_label && $announcement->button_url)
  <tr>
    <td style="padding:4px 28px 26px">
      <a href="{{ $announcement->button_url }}" style="display:inline-block;background:#1688c4;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">{{ $announcement->button_label }}</a>
    </td>
  </tr>
  @endif
  <tr>
    <td style="padding:14px 28px;background:#f9fafb;border-top:1px solid #eef0f3;font-size:12px;color:#98a2b3">
      Sent by {{ config('mail.from.name') }} to the owner and administrators of {{ $clinicName }}. Reply to this email to reach support.
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
