<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a2e; background: #fff; }
    .header { background: linear-gradient(135deg, #1a237e, #283593); color: #fff; padding: 14px 20px; margin-bottom: 16px; border-radius: 4px; }
    .header h1 { font-size: 16px; font-weight: 700; letter-spacing: .5px; }
    .header p { font-size: 8.5px; opacity: .85; margin-top: 3px; }
    .meta { display: flex; gap: 20px; margin-bottom: 14px; font-size: 8px; color: #555; }
    .meta span { background: #f4f6fb; padding: 4px 10px; border-radius: 3px; border-left: 3px solid #3949ab; }
    table { width: 100%; border-collapse: collapse; font-size: 8px; }
    thead tr { background: #283593; color: #fff; }
    thead th { padding: 7px 6px; text-align: left; font-weight: 600; font-size: 7.5px; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
    tbody tr:nth-child(even) { background: #f7f9fd; }
    tbody tr:nth-child(odd)  { background: #fff; }
    tbody td { padding: 6px 6px; vertical-align: top; border-bottom: 1px solid #e8ecf4; }
    .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: 600; }
    .badge-m { background: #dbeafe; color: #1e40af; }
    .badge-f { background: #fce7f3; color: #9d174d; }
    .badge-o { background: #f3f4f6; color: #374151; }
    .high { color: #dc2626; font-weight: 700; }
    .diag { display: inline-block; background: #ede9fe; color: #5b21b6; padding: 1px 4px; border-radius: 2px; margin: 1px 1px 1px 0; font-size: 7px; }
    .footer { margin-top: 18px; font-size: 7.5px; color: #aaa; text-align: right; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    .num { color: #999; font-size: 7.5px; }
</style>
</head>
<body>

<div class="header">
    <h1>&#128065; Patient Consultation Records</h1>
    <p>Exported on {{ $exportedAt }} &mdash; {{ count($records) }} record(s)</p>
</div>

<div class="meta">
    <span><strong>Total Records:</strong> {{ count($records) }}</span>
    <span><strong>Generated:</strong> {{ $exportedAt }}</span>
</div>

<table>
    <thead>
        <tr>
            <th style="width:22px;">#</th>
            <th style="width:90px;">Patient</th>
            <th style="width:55px;">Folder No.</th>
            <th style="width:38px;">Gender</th>
            <th style="width:22px;">Age</th>
            <th style="width:52px;">Date</th>
            <th style="width:90px;">Chief Complaint</th>
            <th style="width:85px;">Diagnoses</th>
            <th style="width:30px;">IOP OD</th>
            <th style="width:30px;">IOP OS</th>
            <th style="width:28px;">VA OD</th>
            <th style="width:28px;">VA OS</th>
            <th style="width:28px;">CDR OD</th>
            <th style="width:28px;">CDR OS</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($records as $i => $r)
        @php
            $patient = $r->patient;
            $age     = $patient?->dob ? \Carbon\Carbon::parse($patient->dob)->age : null;
            $gender  = $patient?->gender ?? null;
        @endphp
        <tr>
            <td class="num">{{ $i + 1 }}</td>
            <td><strong>{{ $patient?->name ?? '—' }}</strong></td>
            <td style="font-family:monospace;">{{ $patient?->pxnumber ?? '—' }}</td>
            <td>
                @if($gender)
                <span class="badge {{ $gender === 'Male' ? 'badge-m' : ($gender === 'Female' ? 'badge-f' : 'badge-o') }}">
                    {{ $gender }}
                </span>
                @else —@endif
            </td>
            <td>{{ $age ?? '—' }}</td>
            <td>{{ $r->created_at->format('d M Y') }}</td>
            <td>{{ \Illuminate\Support\Str::limit($r->chiefComplaint, 45) ?: '—' }}</td>
            <td>
                @forelse($r->diagnoses as $d)
                    <span class="diag">{{ $d->name }}</span>
                @empty —
                @endforelse
            </td>
            <td class="{{ ($r->IOPOD > 21) ? 'high' : '' }}">{{ $r->IOPOD ?? '—' }}</td>
            <td class="{{ ($r->IOPOS > 21) ? 'high' : '' }}">{{ $r->IOPOS ?? '—' }}</td>
            <td>{{ $r->vaOD6m ?? '—' }}</td>
            <td>{{ $r->vaOS6m ?? '—' }}</td>
            <td>{{ $r->cdrOD ?? '—' }}</td>
            <td>{{ $r->cdrOS ?? '—' }}</td>
            <td>{{ \Illuminate\Support\Str::limit($r->notes, 40) ?: '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<div class="footer">
    Eye Clinic System &mdash; Confidential Patient Data &mdash; {{ $exportedAt }}
</div>

</body>
</html>
