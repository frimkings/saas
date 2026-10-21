{{-- A department's roster for the notice board: people down the side, days across, a shift code in each box. --}}
@php
    $days = array_values($report['days']);
    $byName = collect($codes)->keyBy('name');
    $narrow = count($days) > 14;
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: {{ $narrow ? 7 : 9 }}px; color: #0f172a; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .muted { color: #64748b; }
        table { border-collapse: collapse; width: 100%; margin-top: 8px; }
        th, td { border: 1px solid #cbd5e1; padding: 2px; text-align: center; }
        th.name, td.name { text-align: left; white-space: nowrap; padding: 2px 4px; }
        .weekend { background: #f1f5f9; }
        .code { font-weight: bold; }
        .legend span { display: inline-block; margin-right: 8px; }
    </style>
</head>
<body>
    <h1>Roster{{ $unit ? ' · '.$unit : '' }}</h1>
    <div class="muted">{{ $place }} · {{ $first->format('j M') }} to {{ $last->format('j M Y') }}</div>
    <table>
        <thead>
            <tr>
                <th class="name">Staff</th>
                @foreach($days as $day)
                    <th class="{{ $day->isWeekend() ? 'weekend' : '' }}">{{ $day->format('j') }}<br>{{ substr($day->format('D'), 0, 2) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td class="name">{{ $row['staff']->name }}</td>
                    @foreach($days as $day)
                        @php
                            $cell = $row['days'][$day->format('Y-m-d')];
                            $due = $cell['hours'];
                            // Usual hours with the same times as a shift print as that shift, the rest as their times.
                            $shift = $due ? ($byName[$due['shift'] ?? ''] ?? (empty($due['shift']) ? collect($codes)->firstWhere('times', \App\Support\ShiftCodes::times($due['start'], $due['end'])) : null)) : null;
                            $text = $cell['leave_type'] ? 'Lv' : (! $due ? 'Off' : ($shift['code'] ?? \App\Support\ShiftCodes::short($due['start'], $due['end'])));
                        @endphp
                        <td class="code {{ $day->isWeekend() ? 'weekend' : '' }}" style="{{ $cell['leave_type'] ? \App\Support\AttendanceTones::status('leave') : ($shift ? 'background:'.$shift['background'].';color:'.$shift['colour'] : ($text === 'Off' ? 'color:#94a3b8' : 'background:#e0f2fe;color:#075985')) }}">{{ $text }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="legend muted">
        @foreach($codes as $code)<span><b style="padding:0 3px;{{ $code['style'] }}">{{ $code['code'] }}</b> {{ $code['name'] }}</span>@endforeach
        <span><b>Off</b> day off</span><span><b style="padding:0 3px;background:#e0f2fe;color:#075985">8–5</b> usual hours</span><span><b style="padding:0 3px;{{ \App\Support\AttendanceTones::status('leave') }}">Lv</b> on leave</span>
    </p>
</body>
</html>
