<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Needs your attention - {{ $alerts['clinic'] }}</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#172033">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:28px 12px">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border:1px solid #dbe3ed;border-radius:10px;overflow:hidden">

  <tr>
    <td style="background:#0d1b2e;padding:22px 28px">
      <p style="margin:0;font-size:19px;font-weight:bold;color:#fff">{{ $alerts['clinic'] }}</p>
      <p style="margin:6px 0 0;font-size:13px;color:#93c5fd">Needs your attention &middot; {{ $alerts['date'] }}</p>
    </td>
  </tr>

  @foreach($alerts['sections'] as $section)
  <tr>
    <td style="padding:22px 28px 0">
      <p style="margin:0;font-size:16px;font-weight:bold">{{ $section['heading'] }} <span style="font-weight:normal;color:#98a2b3">({{ count($section['rows']) + $section['more'] }} new)</span></p>
      <p style="margin:3px 0 10px;font-size:13px;color:#667085">{{ $section['advice'] }}</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border:1px solid #e4e7ec;border-radius:6px">
        @foreach($section['rows'] as $row)
        <tr>
          <td style="padding:9px 12px;{{ $loop->first ? '' : 'border-top:1px solid #f0f2f5;' }}{{ $row['urgent'] ? 'border-left:3px solid #d92d20;' : '' }}">
            <span style="font-weight:bold">{{ $row['title'] }}</span>
            @if($row['branch'])<span style="font-size:12px;color:#98a2b3"> &middot; {{ $row['branch'] }}</span>@endif
            @if($row['detail'])<div style="font-size:13px;margin-top:2px;color:{{ $row['urgent'] ? '#b42318' : '#475467' }}">{{ $row['detail'] }}</div>@endif
          </td>
        </tr>
        @endforeach
      </table>
      <p style="margin:8px 0 0;font-size:13px;color:#667085">
        @if($section['more'])And {{ $section['more'] }} more. @endif
        @if($section['stillOpen'])Also {{ $section['stillOpen'] }} from earlier {{ $section['stillOpen'] === 1 ? 'is' : 'are' }} still open. @endif
        <a href="{{ $section['url'] }}" style="color:#1688c4">See all &rarr;</a>
      </p>
    </td>
  </tr>
  @endforeach

  <tr><td style="padding:24px 28px 0"></td></tr>
  <tr>
    <td style="padding:14px 28px;background:#f9fafb;border-top:1px solid #eef0f3;font-size:12px;color:#98a2b3;line-height:1.5">
      Only new items are listed; each is reported once, and again if it gets worse (for example from low to out of stock). Sent to the clinic owner by {{ config('app.name') }}; change the owner email under Subscription &rarr; Billing profile.
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
