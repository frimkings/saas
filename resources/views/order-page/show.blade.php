@php
    $optical = $partner->clinic?->name ?? 'Optical centre';
    $statusLabel = fn ($r) => match ($r->status) {
        'sent' => 'Waiting for '.$optical,
        'query' => 'Question for you',
        'accepted' => $r->order_status === 'Collected' ? 'Collected' : ($r->order_status === 'Cancelled' ? 'Cancelled by '.$optical : ($r->order_status ?: 'Accepted')),
        'rejected' => 'Turned down',
        'cancelled' => 'You cancelled it',
        default => ucfirst(str_replace('_', ' ', $r->status)),
    };
    $statusTone = fn ($r) => match (true) {
        $r->status === 'query' => 'warn',
        $r->status === 'rejected', $r->status === 'cancelled', $r->order_status === 'Cancelled' => 'grey',
        in_array($r->order_status, ['Ready for Collection', 'Ready'], true) => 'good',
        default => 'info',
    };
    $rxField = fn ($name, $label, $step, $placeholder = '') => '<label class="f"><span>'.e($label).'</span><input type="number" name="'.$name.'" step="'.$step.'" value="'.e(old($name)).'" placeholder="'.e($placeholder).'" inputmode="decimal"></label>';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Spectacle orders · {{ $optical }}</title>
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/nunito-local.css') }}">
    <style>
        :root{--teal:#0d7377;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--soft:#f8fafc;--warn:#b45309;--bad:#b91c1c;--good:#047857}
        *{box-sizing:border-box}body{margin:0;font-family:'Nunito',system-ui,sans-serif;color:var(--ink);background:var(--soft);line-height:1.45}
        .top{background:linear-gradient(140deg,#0f3460,#0d7377);color:#fff;padding:22px 16px}.top h1{margin:0;font-size:22px}.top p{margin:4px 0 0;opacity:.85;font-size:14px}
        .wrap{max-width:960px;margin:0 auto;padding:0 16px}.main{padding:20px 16px 40px;max-width:960px;margin:0 auto}
        .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px}.card h2{margin:0 0 4px;font-size:17px}.hint{color:var(--muted);font-size:13px;margin:0 0 12px}
        .f{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:700;color:#334155}.f input,.f select,.f textarea{font:inherit;font-weight:400;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;width:100%}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.grid .full{grid-column:1/-1}
        .rx{display:grid;grid-template-columns:70px repeat(4,minmax(0,1fr));gap:8px;align-items:end}.rx .eye{font-weight:800;font-size:13px;padding-bottom:10px}
        .btn{display:inline-block;border:0;border-radius:8px;padding:10px 16px;font:inherit;font-weight:800;cursor:pointer;background:#e2e8f0;color:var(--ink);text-decoration:none}.btn.primary{background:var(--teal);color:#fff}.btn.small{padding:6px 10px;font-size:13px}
        .err{color:var(--bad);font-size:12px;font-weight:600}.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:10px 12px;margin-bottom:16px}
        .alert{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:10px 12px;margin-bottom:12px}
        .job{border-top:1px solid var(--line);padding:12px 0}.job:first-child{border-top:0}.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
        .pill{display:inline-block;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800}.pill.info{background:#e0f2fe;color:#075985}.pill.warn{background:#fef3c7;color:#92400e}.pill.good{background:#d1fae5;color:#065f46}.pill.grey{background:#f1f5f9;color:#475569}
        .q{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-top:8px;font-size:14px}
        .pin input{font-size:24px;letter-spacing:8px;text-align:center;max-width:220px}
        @media(max-width:640px){.grid{grid-template-columns:1fr}.rx{grid-template-columns:44px repeat(2,minmax(0,1fr))}.rx .eye{grid-row:span 2}}
    </style>
</head>
<body>
    <header class="top"><div class="wrap">
        <h1>Spectacle orders for {{ $optical }}</h1>
        <p>For {{ $partner->name }}. Send a prescription for glasses and follow each job.</p>
    </div></header>

    <main class="main">
        @if(! $signedIn)
            <div class="card pin" style="max-width:420px;margin:30px auto">
                <h2>Enter your PIN</h2>
                <p class="hint">{{ $optical }} gave your clinic a 6-digit PIN for this page.</p>
                <form method="POST" action="{{ route('order-page.pin', $partner->page_token) }}">
                    @csrf
                    <label class="f"><span>PIN</span><input type="password" name="pin" inputmode="numeric" autocomplete="off" maxlength="12" autofocus required></label>
                    @error('pin')<p class="err">{{ $message }}</p>@enderror
                    <p style="margin:14px 0 0"><button class="btn primary" type="submit">Open</button></p>
                </form>
            </div>
        @else
            @if(session('sent'))<div class="ok" role="status">{{ session('sent') }}</div>@endif

            <section class="card">
                <div class="row"><h2>Your orders</h2>
                    <form method="POST" action="{{ route('order-page.leave', $partner->page_token) }}">@csrf<button class="btn small" type="submit">Close page</button></form></div>
                <p class="hint">Newest first. The status follows the job at {{ $optical }}.</p>
                @forelse($requests as $r)
                    <div class="job">
                        <div class="row">
                            <div><b>{{ $r->patient_name }}</b>@if($r->sender_reference) <span class="hint">· ref {{ $r->sender_reference }}</span>@endif
                                <div class="hint" style="margin:2px 0 0">Sent {{ $r->created_at->format('j M Y H:i') }}@if($r->sender_name) by {{ $r->sender_name }}@endif @if($r->order_number)· job {{ $r->order_number }}@endif · R {{ $r->eyeLine('od') }} · L {{ $r->eyeLine('os') }}</div></div>
                            <div><span class="pill {{ $statusTone($r) }}">{{ $statusLabel($r) }}</span></div>
                        </div>
                        @if($r->status === 'rejected' && $r->reject_reason)<p class="hint" style="margin:6px 0 0">Reason: {{ $r->reject_reason }}</p>@endif
                        @if($r->status === 'query')
                            <div class="q"><b>{{ $optical }} asks:</b> {{ $r->query_message }}
                                <form method="POST" action="{{ route('order-page.answer', [$partner->page_token, $r->id]) }}" style="margin-top:8px">@csrf
                                    <label class="f"><span>Your answer</span><textarea name="answer" rows="2" required></textarea></label>
                                    <p style="margin:8px 0 0"><button class="btn primary small" type="submit">Send answer</button></p>
                                </form></div>
                        @endif
                        @if($r->isOpen())
                            <details style="margin-top:6px"><summary class="btn small" style="list-style:none;display:inline-block">Cancel order</summary>
                                <form method="POST" action="{{ route('order-page.cancel', [$partner->page_token, $r->id]) }}" style="margin-top:6px">@csrf<span class="hint">Cancel the order for {{ $r->patient_name }}?</span> <button class="btn small" type="submit" style="background:#fee2e2;color:#991b1b">Yes, cancel it</button></form>
                            </details>
                        @endif
                    </div>
                @empty
                    <p class="hint">No orders yet. Send the first one below.</p>
                @endforelse
            </section>

            <section class="card" id="new">
                <h2>Send a new order</h2>
                @if(! $taking)
                    <div class="alert">{{ $optical }} is not taking orders online right now. Please contact them directly.</div>
                @else
                    <p class="hint">{{ $optical }} receives the patient's name, phone and this prescription, prices the job and bills your clinic as usual.</p>
                    @if($errors->any())<div class="alert">Please check the fields marked below.</div>@endif
                    <form method="POST" action="{{ route('order-page.send', $partner->page_token) }}">
                        @csrf
                        <div class="grid">
                            <label class="f"><span>Patient name *</span><input name="patient_name" value="{{ old('patient_name') }}" required maxlength="150">@error('patient_name')<small class="err">{{ $message }}</small>@enderror</label>
                            <label class="f"><span>Patient phone</span><input name="patient_phone" type="tel" value="{{ old('patient_phone') }}" maxlength="50"></label>
                            <label class="f"><span>Your reference (e.g. folder no.)</span><input name="reference" value="{{ old('reference') }}" maxlength="100"></label>
                            <label class="f"><span>Your name *</span><input name="sender_name" value="{{ old('sender_name', session('order_page_sender.'.$partner->id)) }}" required maxlength="120">@error('sender_name')<small class="err">{{ $message }}</small>@enderror</label>
                        </div>
                        <h3 style="font-size:14px;margin:18px 0 8px">Prescription</h3>
                        <div class="rx">
                            <span class="eye">Right (OD)</span>{!! $rxField('od_sph', 'SPH', '0.25', 'Plano') !!}{!! $rxField('od_cyl', 'CYL', '0.25') !!}{!! $rxField('od_axis', 'AXIS', '1') !!}{!! $rxField('od_add', 'ADD', '0.25') !!}
                            <span class="eye">Left (OS)</span>{!! $rxField('os_sph', 'SPH', '0.25', 'Plano') !!}{!! $rxField('os_cyl', 'CYL', '0.25') !!}{!! $rxField('os_axis', 'AXIS', '1') !!}{!! $rxField('os_add', 'ADD', '0.25') !!}
                        </div>
                        @foreach(['od_sph', 'od_cyl', 'od_axis', 'od_add', 'os_sph', 'os_cyl', 'os_axis', 'os_add'] as $field)@error($field)<p class="err">{{ strtoupper(str_replace('_', ' ', $field)) }}: {{ $message }}</p>@enderror @endforeach
                        <div class="grid" style="margin-top:12px">
                            <label class="f"><span>PD (mm)</span><input name="pd" type="number" step="0.5" value="{{ old('pd') }}">@error('pd')<small class="err">{{ $message }}</small>@enderror</label>
                            <label class="f"><span>Lens type</span><select name="lens_type">@foreach(['' => 'Not sure', 'Single vision' => 'Single vision', 'Bifocal' => 'Bifocal', 'Progressive' => 'Progressive', 'Reading' => 'Reading'] as $value => $label)<option value="{{ $value }}" @selected(old('lens_type') === $value)>{{ $label }}</option>@endforeach</select></label>
                            <label class="f full"><span>Lens notes</span><input name="lens_notes" value="{{ old('lens_notes') }}" placeholder="e.g. photochromic, blue filter, 1.67 index" maxlength="1000"></label>
                            <label class="f"><span>Frame</span><select name="frame_choice">@foreach(\App\Models\OpticalOrderRequest::FRAME_CHOICES as $value => $label)<option value="{{ $value }}" @selected(old('frame_choice', 'patient_chooses') === $value)>{{ $label }}</option>@endforeach</select></label>
                            <label class="f"><span>Urgency</span><select name="urgency"><option value="normal">Normal</option><option value="urgent" @selected(old('urgency') === 'urgent')>Urgent</option></select></label>
                            <label class="f full"><span>Frame details</span><input name="frame_notes" value="{{ old('frame_notes') }}" placeholder="Brand, model, colour or condition of the patient's frame" maxlength="1000"></label>
                            <label class="f full"><span>Other notes</span><textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
                        </div>
                        <p style="margin:16px 0 0"><button class="btn primary" type="submit">Send order</button></p>
                    </form>
                @endif
            </section>
        @endif
        <p class="hint" style="text-align:center">Keep this link and PIN within your clinic. {{ $optical }} can change them at any time.</p>
    </main>
</body>
</html>
