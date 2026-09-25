<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $referral->letter_type_label }} – {{ $referral->patient_name }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }

@page { size: A4 portrait; margin: 0; }

html, body {
    width: 210mm; height: 297mm;
    font-family: 'Times New Roman', Times, serif;
    font-size: 10pt; color: #1a1a1a; background: #fff;
}

/* ── Page shell ── */
.page {
    position: relative;
    width: 210mm; height: 297mm;
    overflow: hidden;
    padding: 11mm 16mm 14mm 16mm;
    background: #fff;
}

/* ── Shared: letterhead ── */
.letterhead { text-align:center; padding-bottom:7px; margin-bottom:8px; border-bottom:2px double #003087; }
.lh-logo    { max-height:58px; max-width:130px; object-fit:contain; margin-bottom:3px; }
.lh-org     { font-size:15pt; font-weight:bold; text-transform:uppercase; letter-spacing:2px; color:#003087; line-height:1.1; }
.lh-tagline { font-size:9pt; color:#555; font-style:italic; margin-top:1px; }
.lh-contact { font-size:8.5pt; color:#444; margin-top:3px; }

/* ── Shared: title bar ── */
.title-bar {
    text-align:center; padding:4px 0;
    font-size:10pt; font-weight:bold; letter-spacing:3px; text-transform:uppercase;
    margin-bottom:11px; color:#fff;
}
.title-bar--referral       { background:#003087; }
.title-bar--medical_report { background:#1a7a3c; }
.title-bar--excuse_duty    { background:#e0870a; }

/* ── Shared: field line ── */
.field-line { font-size:9.5pt; margin-bottom:5px; display:flex; align-items:baseline; gap:5px; }
.fl-label   { font-weight:bold; white-space:nowrap; min-width:88px; }
.fl-value   { border-bottom:1px solid #aaa; flex:1; min-height:14px; padding-bottom:1px; color:#111; }
.fl-value--empty { color:#999; font-style:italic; }

.meta-row { display:flex; justify-content:space-between; margin-bottom:5px; }
.meta-row__item { flex:1; }
.meta-row__item + .meta-row__item { margin-left:20px; }

/* ── Shared: section block ── */
.section-block { margin-bottom:7px; }
.sb-label { font-weight:bold; font-size:9.5pt; }
.sb-value { border-bottom:1px solid #aaa; min-height:16px; padding:1px 0; font-size:9.5pt; color:#111; margin-top:2px; line-height:1.4; }
.sb-value--empty { color:#999; font-style:italic; }

/* ── Shared: salutation ── */
.salutation { margin-bottom:5px; font-size:9.5pt; }
.body-text  { margin-bottom:8px; font-size:9.5pt; line-height:1.45; }

/* ── Shared: signature block ── */
.sig-block  { margin-top:14px; display:flex; justify-content:space-between; align-items:flex-end; }
.sig-name   { font-weight:bold; font-size:10pt; }
.sig-title  { font-size:9pt; color:#444; margin-top:1px; }
.sig-right  { text-align:center; min-width:150px; }
.sig-line   { border-top:1px solid #333; margin-bottom:3px; width:150px; }
.sig-label  { font-size:8.5pt; color:#555; }

/* ── Shared: footer ── */
.page-footer {
    position:absolute; bottom:8mm; left:16mm; right:16mm;
    border-top:1px solid #ccc; padding-top:4px;
    font-size:7.5pt; color:#999; text-align:center;
}

/* ─────────────────────────────────────────── */
/* REFERRAL-SPECIFIC                           */
/* ─────────────────────────────────────────── */
.findings-box { border:1px solid #ccc; border-radius:3px; padding:7px 11px; margin-bottom:9px; background:#fafafa; }
.fb-title     { font-weight:bold; font-size:8.5pt; text-transform:uppercase; letter-spacing:.5px; color:#003087; margin-bottom:6px; border-bottom:1px solid #ddd; padding-bottom:3px; }
.findings-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px 20px; }
.fi-item       { display:flex; gap:5px; align-items:baseline; font-size:9pt; }
.fi-label      { font-weight:bold; white-space:nowrap; min-width:82px; }
.fi-value      { border-bottom:1px solid #ccc; flex:1; min-height:13px; color:#111; }

/* ─────────────────────────────────────────── */
/* MEDICAL REPORT-SPECIFIC                     */
/* ─────────────────────────────────────────── */
.mr-field { margin-bottom:9px; }
.mr-label { font-weight:bold; font-size:9pt; text-transform:uppercase; letter-spacing:.4px; color:#1a7a3c; margin-bottom:3px; }
.mr-value { font-size:9.5pt; color:#111; line-height:1.45; min-height:18px; padding:3px 6px; background:#f9f9f9; border:1px solid #ddd; border-radius:3px; }
.mr-value--empty { color:#999; font-style:italic; }

/* ─────────────────────────────────────────── */
/* EXCUSE DUTY-SPECIFIC                        */
/* ─────────────────────────────────────────── */
.excuse-dates-box {
    display:flex; gap:20px; align-items:stretch;
    border:2px solid #e0870a; border-radius:6px; padding:10px 14px;
    margin-bottom:12px; background:#fffbf0;
}
.excuse-date-item { flex:1; text-align:center; }
.excuse-date-label { font-size:8.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:.5px; color:#e0870a; margin-bottom:4px; }
.excuse-date-value { font-size:13pt; font-weight:bold; color:#1a1a1a; }
.excuse-divider { width:1px; background:#e0870a; opacity:.3; align-self:stretch; }
.excuse-body p  { font-size:9.5pt; line-height:1.6; margin-bottom:8px; }
.excuse-body strong { color:#1a1a1a; }

/* ── Screen preview ── */
@media screen {
    body { background:#e0e0e0; display:flex; flex-direction:column; align-items:center; padding:24px 0 40px; }
    .page { box-shadow:0 4px 24px rgba(0,0,0,.25); }
    .no-print { margin-top:20px; font-family:Arial,sans-serif; }
}
@media print {
    html,body { background:#fff; }
    body { padding:0; display:block; }
    .no-print { display:none !important; }
}
</style>
</head>
<body>
<div class="page">

    {{-- ══════════════════ SHARED LETTERHEAD ══════════════════ --}}
    <div class="letterhead">
        @if($settings->logoDataUri())
            <img src="{{ $settings->logoDataUri() }}" class="lh-logo" alt="Clinic Logo">
        @endif
        <div class="lh-org">{{ $settings->clinic_name }}</div>
        <div class="lh-tagline">"More Than Eye Care"</div>
        <div class="lh-contact">
            {{ $settings->clinic_address }}
            @if($settings->clinic_contact) &nbsp;|&nbsp; Tel: {{ $settings->clinic_contact }} @endif
            @if($settings->clinic_email) &nbsp;|&nbsp; {{ $settings->clinic_email }} @endif
        </div>
    </div>

    {{-- Title bar – colour varies by type --}}
    <div class="title-bar title-bar--{{ $referral->letter_type }}">
        @if($referral->letter_type === 'referral')       RE: PATIENT REFERRAL
        @elseif($referral->letter_type === 'medical_report') MEDICAL REPORT
        @else                                            MEDICAL EXCUSE DUTY LETTER
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════ --}}
    {{-- REFERRAL LETTER LAYOUT                                 --}}
    {{-- ══════════════════════════════════════════════════════ --}}
    @if($referral->letter_type === 'referral')

    <div class="meta-row">
        <div class="meta-row__item">
            <div class="field-line"><span class="fl-label">Date:</span><span class="fl-value">{{ $referral->referral_date->format('F d, Y') }}</span></div>
        </div>
        <div class="meta-row__item">
            <div class="field-line"><span class="fl-label">Referral To:</span><span class="fl-value">{{ $referral->referral_to }}</span></div>
        </div>
    </div>

    <div class="field-line"><span class="fl-label">Patient:</span><span class="fl-value">{{ $referral->patient_name }}</span></div>
    <div class="meta-row">
        <div class="meta-row__item">
            <div class="field-line"><span class="fl-label">Age / Sex:</span><span class="fl-value {{ $referral->patient_age_sex ? '' : 'fl-value--empty' }}">{{ $referral->patient_age_sex ?: '&nbsp;' }}</span></div>
        </div>
        <div class="meta-row__item">
            <div class="field-line"><span class="fl-label">Contact:</span><span class="fl-value {{ $referral->patient_contact ? '' : 'fl-value--empty' }}">{{ $referral->patient_contact ?: '&nbsp;' }}</span></div>
        </div>
    </div>

    <p class="salutation">Dear Sir / Madam,</p>
    <p class="body-text">I am referring the above-named patient to your facility for further evaluation and management. Please find the clinical summary below.</p>

    <div class="findings-box">
        <div class="fb-title">Clinical Findings</div>
        <div class="findings-grid">
            <div class="fi-item"><span class="fi-label">Complaint:</span><span class="fi-value">{{ $referral->complaint ?: '—' }}</span></div>
            <div class="fi-item"><span class="fi-label">Refraction:</span><span class="fi-value">{{ $referral->refraction ?: '—' }}</span></div>
            <div class="fi-item"><span class="fi-label">VA — OD:</span><span class="fi-value">{{ $referral->va_od ?: '—' }}</span></div>
            <div class="fi-item"><span class="fi-label">VA — OS:</span><span class="fi-value">{{ $referral->va_os ?: '—' }}</span></div>
            <div class="fi-item"><span class="fi-label">IOP:</span><span class="fi-value">{{ $referral->iop ?: '—' }}</span></div>
            <div class="fi-item"><span class="fi-label">Anterior:</span><span class="fi-value">{{ $referral->anterior_segment ?: '—' }}</span></div>
            <div class="fi-item" style="grid-column:1/-1;"><span class="fi-label">Posterior:</span><span class="fi-value">{{ $referral->posterior_segment ?: '—' }}</span></div>
        </div>
    </div>

    <div class="section-block"><span class="sb-label">Diagnosis:</span><div class="sb-value {{ $referral->diagnosis ? '' : 'sb-value--empty' }}">{{ $referral->diagnosis ?: 'Not specified' }}</div></div>
    <div class="section-block"><span class="sb-label">Reason for Referral:</span><div class="sb-value {{ $referral->reason_for_referral ? '' : 'sb-value--empty' }}">{{ $referral->reason_for_referral ?: 'Not specified' }}</div></div>
    <div class="section-block"><span class="sb-label">Management Given / Notes:</span><div class="sb-value {{ $referral->management ? '' : 'sb-value--empty' }}">{{ $referral->management ?: 'None' }}</div></div>

    <p class="body-text" style="margin-top:10px;">Kindly review and manage this patient accordingly. Please do not hesitate to contact us for any further information. Thank you for your kind attention.</p>

    @endif

    {{-- ══════════════════════════════════════════════════════ --}}
    {{-- MEDICAL REPORT LAYOUT                                  --}}
    {{-- ══════════════════════════════════════════════════════ --}}
    @if($referral->letter_type === 'medical_report')

    <div class="meta-row" style="margin-bottom:8px;">
        <div class="meta-row__item"><div class="field-line"><span class="fl-label">Patient Name:</span><span class="fl-value">{{ $referral->patient_name }}</span></div></div>
        <div class="meta-row__item"><div class="field-line"><span class="fl-label">Date:</span><span class="fl-value">{{ $referral->referral_date->format('F d, Y') }}</span></div></div>
    </div>
    <div class="field-line" style="margin-bottom:10px;">
        <span class="fl-label">Age / Sex:</span>
        <span class="fl-value {{ $referral->patient_age_sex ? '' : 'fl-value--empty' }}">{{ $referral->patient_age_sex ?: '&nbsp;' }}</span>
        @if($referral->patient_contact)
        &nbsp;&nbsp;<span class="fl-label" style="min-width:60px;">Contact:</span>
        <span class="fl-value">{{ $referral->patient_contact }}</span>
        @endif
    </div>

    <div class="mr-field">
        <div class="mr-label">Clinical Findings</div>
        <div class="mr-value {{ $referral->clinical_findings ? '' : 'mr-value--empty' }}">{{ $referral->clinical_findings ?: 'Not recorded' }}</div>
    </div>

    <div class="mr-field">
        <div class="mr-label">Diagnosis</div>
        <div class="mr-value {{ $referral->diagnosis ? '' : 'mr-value--empty' }}">{{ $referral->diagnosis ?: 'Not specified' }}</div>
    </div>

    <div class="mr-field">
        <div class="mr-label">Management / Treatment</div>
        <div class="mr-value {{ $referral->treatment ? '' : 'mr-value--empty' }}">{{ $referral->treatment ?: 'None recorded' }}</div>
    </div>

    <div class="mr-field">
        <div class="mr-label">Recommendation</div>
        <div class="mr-value {{ $referral->recommendation ? '' : 'mr-value--empty' }}">{{ $referral->recommendation ?: 'None' }}</div>
    </div>

    @endif

    {{-- ══════════════════════════════════════════════════════ --}}
    {{-- EXCUSE DUTY LAYOUT                                     --}}
    {{-- ══════════════════════════════════════════════════════ --}}
    @if($referral->letter_type === 'excuse_duty')

    <div class="field-line" style="margin-bottom:10px;">
        <span class="fl-label">Date:</span>
        <span class="fl-value">{{ $referral->referral_date->format('F d, Y') }}</span>
    </div>

    <p class="body-text" style="font-weight:bold;">To Whom It May Concern,</p>

    <div class="excuse-body">
        <p>
            This is to certify that <strong>{{ $referral->patient_name }}</strong>
            @if($referral->patient_age_sex) ({{ $referral->patient_age_sex }}) @endif
            was seen and treated at <strong>{{ $settings->clinic_name }}</strong>
            on {{ $referral->referral_date->format('F d, Y') }}.
        </p>

        @if($referral->excuse_from_date && $referral->excuse_to_date)
        <div class="excuse-dates-box">
            <div class="excuse-date-item">
                <div class="excuse-date-label">Excused From</div>
                <div class="excuse-date-value">{{ $referral->excuse_from_date->format('M d, Y') }}</div>
            </div>
            <div class="excuse-divider"></div>
            <div class="excuse-date-item">
                <div class="excuse-date-label">Excused Until</div>
                <div class="excuse-date-value">{{ $referral->excuse_to_date->format('M d, Y') }}</div>
            </div>
            <div class="excuse-divider"></div>
            <div class="excuse-date-item">
                <div class="excuse-date-label">Duration</div>
                <div class="excuse-date-value">{{ $referral->excuse_from_date->diffInDays($referral->excuse_to_date) + 1 }} day(s)</div>
            </div>
        </div>
        @endif

        @php
            $excuseNotes = $referral->excuse_notes ?? \App\Models\Referral::defaultExcuseNotes();
            $excuseNotes = str_replace(
                ['{excused_from}', '{excused_until}'],
                [
                    optional($referral->excuse_from_date)->format('F d, Y'),
                    optional($referral->excuse_to_date)->format('F d, Y'),
                ],
                $excuseNotes
            );
        @endphp
        @foreach(preg_split('/\r\n|\r|\n/', $excuseNotes) as $paragraph)
            @if(trim($paragraph) !== '')
                <p>{{ $paragraph }}</p>
            @endif
        @endforeach
    </div>

    @endif

    {{-- ══════════════════════════════════════════════════════ --}}
    {{-- SHARED SIGNATURE BLOCK                                 --}}
    {{-- ══════════════════════════════════════════════════════ --}}
    <div class="sig-block">
        <div>
            
            <div class="sig-title"> {{ $settings->clinic_name }}</div>
            <div class="sig-title" style="margin-top:3px; font-size:8.5pt; color:#888;">
                Issued by: {{ $referral->referredBy->name ?? 'Unknown' }}
                &nbsp;|&nbsp; {{ $referral->referral_date->format('M d, Y') }}
            </div>
        </div>
        <div class="sig-right">
            <div style="height:28px;"></div>
            <div class="sig-line"></div>
            <div class="sig-label">Signature &amp; Stamp</div>
        </div>
    </div>

    {{-- Footer ── always pinned to bottom --}}
    <div class="page-footer">
        {{ $settings->clinic_name }} &nbsp;|&nbsp; {{ $settings->clinic_address }}
        @if($settings->clinic_contact) &nbsp;|&nbsp; Tel: {{ $settings->clinic_contact }} @endif
        @if($settings->clinic_email) &nbsp;|&nbsp; {{ $settings->clinic_email }} @endif
        &nbsp;|&nbsp; Generated: {{ now()->format('M d, Y H:i') }}
    </div>

</div>{{-- end .page --}}

<div class="no-print" style="text-align:center;">
    <button onclick="window.print()" style="background:#003087;color:#fff;border:none;padding:10px 28px;border-radius:6px;font-size:14px;cursor:pointer;">
        🖨&nbsp; Print Letter
    </button>
    <button onclick="window.close()" style="background:#6c757d;color:#fff;border:none;padding:10px 28px;border-radius:6px;font-size:14px;cursor:pointer;margin-left:10px;">
        Close
    </button>
</div>
</body>
</html>
