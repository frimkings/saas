@php
    use App\Services\Attendance\AttendanceAnalyzer;

    $shade = ['on_time' => '#e8f7ef', 'late' => '#fde9b5', 'absent' => '#f9cfcf', 'off' => '#eeeeee', 'holiday' => '#e4dcf7', 'leave' => '#d9e6fb', 'worked_off' => '#dff1fa', 'none' => '#ffffff'];
    $code = ['absent' => 'A', 'off' => '', 'holiday' => 'H', 'leave' => 'LV', 'none' => ''];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
        .header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #333; padding-bottom: 6px; }
        .clinic-logo { max-height: 44px; max-width: 120px; margin-bottom: 4px; }
        .clinic-name { font-size: 15px; font-weight: bold; text-transform: uppercase; }
        .muted { color: #666; }
        h2 { font-size: 12px; margin: 4px 0 2px; }
        h3 { font-size: 10px; margin: 12px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 3px 4px; text-align: left; }
        th { background-color: #f2f2f2; }
        .num { text-align: right; }
        .grid th, .grid td { padding: 2px 1px; text-align: center; font-size: 7px; }
        .grid td.name, .grid th.name { text-align: left; padding-left: 4px; font-size: 8px; width: 110px; }
        .legend span { display: inline-block; padding: 1px 5px; margin-right: 6px; border: 1px solid #ccc; }
        .signature { margin-top: 26px; }
        .signature td { border: 0; padding-top: 18px; width: 50%; }
    </style>
</head>
<body>
    <div class="header">
        @if(! empty($appSettings) && $appSettings->logoDataUri())
            <img src="{{ $appSettings->logoDataUri() }}" class="clinic-logo" alt="">
        @endif
        <div class="clinic-name">{{ $appSettings->clinic_name ?? $place }}</div>
        <h2>STAFF ATTENDANCE · {{ strtoupper($month->format('F Y')) }}</h2>
        <div class="muted">
            {{ $place }} ·
            {{ $report['covered_until'] ? 'Covers up to '.\Carbon\CarbonImmutable::parse($report['covered_until'])->format('j M Y') : 'Nothing received for this month' }}
            · Printed {{ $generatedAt->format('j M Y g:i A') }}
        </div>
    </div>

    <h3>Summary</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Name</th><th>Department</th>
                <th class="num">Expected</th><th class="num">Present</th><th class="num">On time</th><th class="num">Late</th><th class="num">Time late</th>
                <th class="num">Absent</th><th class="num">Leave</th><th class="num">Left early</th><th class="num">No clock-out</th><th class="num">Usual arrival</th><th class="num">Hours on site</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                @php $t = $row['totals']; @endphp
                <tr>
                    <td>{{ $row['staff']->employee_no }}</td>
                    <td>{{ $row['staff']->name }}</td>
                    <td>{{ $row['staff']->department }}</td>
                    <td class="num">{{ $t['expected'] }}</td>
                    <td class="num">{{ $t['present'] }}</td>
                    <td class="num">{{ $t['on_time'] }}</td>
                    <td class="num">{{ $t['late'] }}</td>
                    <td class="num">{{ $t['late_minutes'] ? AttendanceAnalyzer::duration($t['late_minutes']) : '–' }}</td>
                    <td class="num">{{ $t['absent'] }}</td>
                    <td class="num">{{ $t['leave'] }}</td>
                    <td class="num">{{ $t['left_early'] }}</td>
                    <td class="num">{{ $t['no_clock_out'] }}</td>
                    <td class="num">{{ AttendanceAnalyzer::time($t['average_arrival']) ?? '–' }}</td>
                    <td class="num">{{ $t['minutes_worked'] ? AttendanceAnalyzer::duration($t['minutes_worked']) : '–' }}</td>
                </tr>
            @empty
                <tr><td colspan="14" style="text-align:center">No staff are being followed.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Arrival time, day by day</h3>
    <table class="grid">
        <thead>
            <tr>
                <th class="name">Name</th>
                @foreach($report['days'] as $day)
                    <th>{{ $day->format('D')[0] }}<br>{{ $day->day }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td class="name">{{ $row['staff']->name }}</td>
                    @foreach($row['days'] as $day)
                        <td style="background-color: {{ $shade[$day['status']] }}">{!! $code[$day['status']] ?? str_replace(' ', '<br>', e(AttendanceAnalyzer::time($day['in']))) !!}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="legend">
        <span style="background-color: {{ $shade['on_time'] }}">On time</span>
        <span style="background-color: {{ $shade['late'] }}">Late</span>
        <span style="background-color: {{ $shade['absent'] }}">A · Absent</span>
        <span style="background-color: {{ $shade['leave'] }}">LV · On leave</span>
        <span style="background-color: {{ $shade['holiday'] }}">H · Holiday</span>
        <span style="background-color: {{ $shade['worked_off'] }}">Worked on a day off</span>
        <span style="background-color: {{ $shade['off'] }}">Day off</span>
    </p>
    @if($report['holidays'])
        <p class="muted">Holidays: @foreach($report['holidays'] as $date => $name){{ \Carbon\CarbonImmutable::parse($date)->format('j M') }} {{ $name }}{{ $loop->last ? '' : ', ' }}@endforeach</p>
    @endif
    <p class="muted">Late means arriving more than {{ $report['settings']->grace_minutes }} minutes after the start of the working day. The first punch of a day is the arrival, the last the departure.</p>

    <table class="signature">
        <tr><td>Prepared by: ______________________________</td><td>Approved by: ______________________________</td></tr>
    </table>
</body>
</html>
