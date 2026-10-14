{{-- Staff attendance in the owner's daily, weekly and monthly emails: one block per branch (App\Services\Attendance\AttendanceDigest). --}}
@php
    $toneColour = ['absent' => '#b42318', 'late' => '#b54708', 'note' => '#475467'];
    $several = count($blocks) > 1;
@endphp
  <tr>
    <td style="padding:18px 28px 4px">
      <p style="margin:0 0 8px;font-size:13px;font-weight:bold;color:#344054;text-transform:uppercase;letter-spacing:.04em">Staff attendance</p>
      @foreach($blocks as $branch => $a)
        @if($several || ! empty($named))<p style="margin:{{ $loop->first ? '0' : '14px' }} 0 6px;font-size:13px;font-weight:bold;color:#172033">{{ $branch }}</p>@endif

        @if($a['headline'])
        <p style="margin:0 0 8px;font-size:14px">
          <b>{{ $a['headline'] }}</b>@if($a['rate'] !== null && $a['period'] !== 'daily') &middot; {{ rtrim(rtrim(number_format($a['rate'], 1), '0'), '.') }}%@endif
          @if($a['period'] !== 'daily' && $a['rate'] !== null && $a['previousRate'] !== null)
            @php $move = round($a['rate'] - $a['previousRate'], 1); @endphp
            <span style="font-size:12px;color:{{ abs($move) < 0.05 ? '#667085' : ($move > 0 ? '#15803d' : '#b42318') }}">
              @if(abs($move) < 0.05) same as {{ $compareWith }} @else {!! $move > 0 ? '&#9650;' : '&#9660;' !!} {{ number_format(abs($move), 1) }} points vs {{ $compareWith }} @endif
            </span>
          @endif
        </p>
        @endif

        @if(! empty($a['departments']))
        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:12px;border:1px solid #e4e7ec;border-radius:6px;margin-bottom:10px">
          <tr style="background:#f9fafb;color:#667085">
            <td style="padding:7px 8px">Department</td>
            <td style="padding:7px 6px;text-align:right">Present</td>
            <td style="padding:7px 6px;text-align:right">Attendance</td>
            <td style="padding:7px 6px;text-align:right">Late</td>
            <td style="padding:7px 8px;text-align:right">Absent</td>
          </tr>
          @foreach($a['departments'] as $unit)
          <tr>
            <td style="padding:7px 8px;border-top:1px solid #f0f2f5">{{ $unit['name'] }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right;white-space:nowrap">{{ $unit['present'] }} / {{ $unit['expected'] }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right">{{ $unit['expected'] ? round($unit['present'] / $unit['expected'] * 100).'%' : '–' }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right;{{ $unit['late'] ? 'color:#b54708;font-weight:bold' : '' }}">{{ $unit['late'] }}</td>
            <td style="padding:7px 8px;border-top:1px solid #f0f2f5;text-align:right;{{ $unit['absent'] ? 'color:#b42318;font-weight:bold' : '' }}">{{ $unit['absent'] }}</td>
          </tr>
          @endforeach
        </table>
        @endif

        @if($a['period'] === 'daily' && $a['headline'])
          @if($a['exceptions'])
          <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border:1px solid #e4e7ec;border-radius:6px">
            @foreach($a['exceptions'] as $row)
            <tr>
              <td style="padding:8px 12px;{{ $loop->first ? '' : 'border-top:1px solid #f0f2f5;' }}">{{ $row['name'] }}</td>
              <td style="padding:8px 12px;text-align:right;color:{{ $toneColour[$row['tone']] }};{{ $loop->first ? '' : 'border-top:1px solid #f0f2f5;' }}">{{ $row['detail'] }}</td>
            </tr>
            @endforeach
          </table>
          @else
          <p style="margin:0;font-size:14px;color:#15803d">Everyone expected was in on time.</p>
          @endif
        @endif

        @if($a['rows'])
        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:12px;border:1px solid #e4e7ec;border-radius:6px">
          <tr style="background:#f9fafb;color:#667085">
            <td style="padding:7px 8px">Staff</td>
            <td style="padding:7px 6px;text-align:right">Present</td>
            <td style="padding:7px 6px;text-align:right">Late</td>
            <td style="padding:7px 6px;text-align:right">Time late</td>
            <td style="padding:7px 6px;text-align:right">Absent</td>
            <td style="padding:7px 6px;text-align:right">Leave</td>
            <td style="padding:7px 6px;text-align:right">Long breaks</td>
            @if($a['period'] === 'monthly')<td style="padding:7px 8px;text-align:right">On site</td>@endif
          </tr>
          @foreach($a['rows'] as $row)
          <tr>
            <td style="padding:7px 8px;border-top:1px solid #f0f2f5">{{ $row['name'] }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right;white-space:nowrap">{{ $row['present'] }} / {{ $row['expected'] }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right;{{ $row['late'] ? 'color:#b54708;font-weight:bold' : '' }}">{{ $row['late'] }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right;white-space:nowrap">{{ $row['late_time'] ?? '–' }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right;{{ $row['absent'] ? 'color:#b42318;font-weight:bold' : '' }}">{{ $row['absent'] }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right">{{ $row['leave'] }}</td>
            <td style="padding:7px 6px;border-top:1px solid #f0f2f5;text-align:right">{{ $row['long_breaks'] }}</td>
            @if($a['period'] === 'monthly')<td style="padding:7px 8px;border-top:1px solid #f0f2f5;text-align:right;white-space:nowrap">{{ $row['hours'] ?? '–' }}</td>@endif
          </tr>
          @endforeach
        </table>
        @endif

        @if($a['mostLate'])<p style="margin:8px 0 0;font-size:13px;color:#475467"><b>Most time lost to lateness:</b> {{ $a['mostLate'] }}</p>@endif
        @if($a['perfect'])<p style="margin:6px 0 0;font-size:13px;color:#15803d"><b>Never late or absent:</b> {{ implode(', ', $a['perfect']) }}</p>@endif
        @if($a['note'])<p style="margin:8px 0 0;font-size:12px;color:#98a2b3">{{ $a['note'] }}</p>@endif
      @endforeach
      <p style="margin:10px 0 0;font-size:13px"><a href="{{ $url }}" style="color:#1688c4">Open attendance</a></p>
    </td>
  </tr>
