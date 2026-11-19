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
    $rxInput = fn ($name, $step, $label, $placeholder = '') => '<input type="number" name="'.$name.'" step="'.$step.'" value="'.e(old($name)).'" placeholder="'.e($placeholder).'" inputmode="decimal" aria-label="'.e($label).'" data-rx>';
    $sender = old('sender_name', session('order_page_sender.'.$partner->id));
    $questions = $signedIn ? $requests->where('status', 'query')->count() : 0;
    // Orders tab first after sending, or while the optical centre waits on an answer.
    $startTab = $signedIn && (session('sent') || $questions) && ! $errors->any() ? 'orders' : 'send';
    $openNotes = old('notes') || old('lens_notes');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Spectacle orders · {{ $optical }}</title>
    <link rel="stylesheet" href="{{ asset('backend/plugins/vendor-css/nunito-local.css') }}">
    <style>
        :root{--teal:#0d7377;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--soft:#f8fafc;--warn:#b45309;--bad:#b91c1c;--good:#047857}
        *{box-sizing:border-box}body{margin:0;font-family:'Nunito',system-ui,sans-serif;color:var(--ink);background:var(--soft);line-height:1.45}
        .top{background:linear-gradient(140deg,#0f3460,#0d7377);color:#fff;padding:14px 16px}.top .wrap{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
        .top h1{margin:0;font-size:19px}.top p{margin:2px 0 0;opacity:.85;font-size:13px}.top .btn{background:rgba(255,255,255,.15);color:#fff}
        .wrap{max-width:960px;margin:0 auto}.main{padding:14px 16px 90px;max-width:960px;margin:0 auto}
        .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:14px}.card h2{margin:0 0 4px;font-size:17px}.hint{color:var(--muted);font-size:13px;margin:0 0 10px}
        .f{display:flex;flex-direction:column;gap:3px;font-size:12px;font-weight:700;color:#334155;min-width:0}
        input,select,textarea{font:inherit;font-size:14px;font-weight:400;padding:8px 9px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;width:100%;color:var(--ink)}
        .grid{display:grid;gap:10px}.g3{grid-template-columns:repeat(3,minmax(0,1fr))}.g4{grid-template-columns:repeat(4,minmax(0,1fr))}
        .tabs{display:flex;gap:6px;margin-bottom:12px}.tab{border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 14px;font:inherit;font-weight:800;font-size:14px;cursor:pointer;color:#334155}
        .tab[aria-selected=true]{background:var(--teal);border-color:var(--teal);color:#fff}.tab .n{display:inline-block;min-width:20px;padding:0 6px;margin-left:4px;border-radius:999px;background:rgba(15,23,42,.08);font-size:12px}
        .tab .n.warn{background:#fde68a;color:#92400e}
        .sec{font-size:13px;font-weight:800;margin:14px 0 6px;color:#334155}
        .rx{display:grid;grid-template-columns:52px repeat(4,minmax(0,1fr));gap:6px;align-items:center}.rx .h{font-size:11px;font-weight:800;color:var(--muted);text-align:center}.rx .eye{font-weight:800;font-size:13px}
        .rxfoot{display:grid;grid-template-columns:110px minmax(0,1fr) auto;gap:10px;align-items:end;margin-top:10px}
        .btn{display:inline-block;border:0;border-radius:8px;padding:9px 14px;font:inherit;font-weight:800;cursor:pointer;background:#e2e8f0;color:var(--ink);text-decoration:none;white-space:nowrap}
        .btn.primary{background:var(--teal);color:#fff}.btn.outline{background:#fff;border:1px solid var(--teal);color:var(--teal)}.btn.small{padding:5px 10px;font-size:13px}.btn:disabled{opacity:.6;cursor:wait}
        .err{color:var(--bad);font-size:12px;font-weight:600}.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:10px 12px;margin-bottom:12px}
        .alert{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:10px 12px;margin-bottom:12px}
        .job{border-top:1px solid var(--line);padding:10px 0}.job:first-child{border-top:0}.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start}
        .pill{display:inline-block;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800}.pill.info{background:#e0f2fe;color:#075985}.pill.warn{background:#fef3c7;color:#92400e}.pill.good{background:#d1fae5;color:#065f46}.pill.grey{background:#f1f5f9;color:#475569}
        .q{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-top:8px;font-size:14px}
        .pin input{font-size:24px;letter-spacing:8px;text-align:center;max-width:220px}
        .lenses{margin-top:10px}.lenses table{width:100%;border-collapse:collapse;font-size:13px}.lenses th{font-size:11px;color:var(--muted);text-align:left;font-weight:800;padding:4px 6px;border-bottom:1px solid var(--line)}
        .lenses td{padding:7px 6px;border-bottom:1px solid var(--line);vertical-align:middle}.lenses tr.pick{cursor:pointer}.lenses tr.pick:hover{background:#f0fdfa}.lenses tr.on{background:#ccfbf1}
        .lenses .r{text-align:right;white-space:nowrap}.stk{font-weight:800;color:var(--good)}.ord{font-weight:700;color:var(--warn)}
        .note{font-size:12px;color:var(--muted);margin:6px 0 0}.picked{margin-top:8px;font-size:13px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:7px 10px;display:flex;justify-content:space-between;gap:8px;align-items:center}
        .sendbar{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid var(--line);padding:10px 16px;box-shadow:0 -4px 14px rgba(15,23,42,.06)}.sendbar .wrap{display:flex;justify-content:space-between;align-items:center;gap:12px}
        details.more>summary{list-style:none;cursor:pointer;color:var(--teal);font-weight:800;font-size:13px;margin-top:12px}details.more>summary::-webkit-details-marker{display:none}
        .sender{font-size:13px;color:var(--muted);margin:0 0 10px}.sender b{color:var(--ink)}.link{background:none;border:0;padding:0;font:inherit;color:var(--teal);font-weight:800;cursor:pointer}
        [hidden]{display:none!important}
        @media(max-width:640px){.g3,.g4{grid-template-columns:1fr 1fr}.g3>.wide,.g4>.wide{grid-column:1/-1}.rx{grid-template-columns:40px repeat(4,minmax(0,1fr));gap:4px}.rx input{padding:7px 4px;font-size:13px}
            .rxfoot{grid-template-columns:90px minmax(0,1fr)}.rxfoot .btn{grid-column:1/-1}.lenses .hide-sm{display:none}}
    </style>
</head>
<body>
    <header class="top"><div class="wrap">
        <div><h1>Spectacle orders for {{ $optical }}</h1><p>For {{ $partner->name }}. Send a prescription for glasses and follow each job.</p></div>
        @if($signedIn)
            <form method="POST" action="{{ route('order-page.leave', $partner->page_token) }}">@csrf<button class="btn small" type="submit">Close page</button></form>
        @endif
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

            <div class="tabs" role="tablist">
                <button type="button" class="tab" role="tab" data-tab="send" aria-selected="{{ $startTab === 'send' ? 'true' : 'false' }}">Send order</button>
                <button type="button" class="tab" role="tab" data-tab="orders" aria-selected="{{ $startTab === 'orders' ? 'true' : 'false' }}">Your orders<span class="n {{ $questions ? 'warn' : '' }}" title="{{ $questions ? $questions.' waiting for your answer' : '' }}">{{ $questions ?: $requests->count() }}</span></button>
            </div>

            <section class="card" data-panel="orders" @if($startTab !== 'orders') hidden @endif>
                <h2>Your orders</h2>
                <p class="hint">Newest first. The status follows the job at {{ $optical }}.</p>
                @forelse($requests as $r)
                    @if($loop->index === 5)<details class="more"><summary>Show all {{ $requests->count() }} orders</summary>@endif
                    <div class="job">
                        <div class="row">
                            <div><b>{{ $r->patient_name }}</b>@if($r->sender_reference) <span class="hint">· ref {{ $r->sender_reference }}</span>@endif
                                <div class="hint" style="margin:2px 0 0">Sent {{ $r->created_at->format('j M Y H:i') }}@if($r->sender_name) by {{ $r->sender_name }}@endif @if($r->order_number)· job {{ $r->order_number }}@endif · R {{ $r->eyeLine('od') }} · L {{ $r->eyeLine('os') }}@if(data_get($r->lens_choice, 'label')) · {{ data_get($r->lens_choice, 'label') }}@endif</div></div>
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
                    @if($loop->last && $loop->count > 5)</details>@endif
                @empty
                    <p class="hint">No orders yet. Send the first one from the Send order tab.</p>
                @endforelse
            </section>

            <section class="card" data-panel="send" @if($startTab !== 'send') hidden @endif>
                @if(! $taking)
                    <div class="alert" style="margin:0">{{ $optical }} is not taking orders online right now. Please contact them directly.</div>
                @else
                    @if($errors->any())<div class="alert">Please check the fields marked below.</div>@endif
                    <form method="POST" action="{{ route('order-page.send', $partner->page_token) }}" id="order-form">
                        @csrf
                        @if($sender && ! $errors->has('sender_name'))
                            <p class="sender" id="sender-line">Sending as <b>{{ $sender }}</b> · <button type="button" class="link" id="sender-change">change</button></p>
                        @endif
                        <div class="grid {{ $sender && ! $errors->has('sender_name') ? 'g3' : 'g4' }}">
                            <label class="f wide"><span>Patient name *</span><input name="patient_name" value="{{ old('patient_name') }}" required maxlength="150">@error('patient_name')<small class="err">{{ $message }}</small>@enderror</label>
                            <label class="f"><span>Patient phone</span><input name="patient_phone" type="tel" value="{{ old('patient_phone') }}" maxlength="50"></label>
                            <label class="f"><span>Your ref. (folder no.)</span><input name="reference" value="{{ old('reference') }}" maxlength="100"></label>
                            <label class="f wide" id="sender-field" @if($sender && ! $errors->has('sender_name')) hidden @endif><span>Your name *</span><input name="sender_name" value="{{ $sender }}" required maxlength="120">@error('sender_name')<small class="err">{{ $message }}</small>@enderror</label>
                        </div>

                        <div class="sec">Prescription</div>
                        <div class="rx">
                            <span></span><span class="h">SPH</span><span class="h">CYL</span><span class="h">AXIS</span><span class="h">ADD</span>
                            <span class="eye">Right</span>{!! $rxInput('od_sph', '0.25', 'Right SPH', 'Plano') !!}{!! $rxInput('od_cyl', '0.25', 'Right CYL') !!}{!! $rxInput('od_axis', '1', 'Right AXIS') !!}{!! $rxInput('od_add', '0.25', 'Right ADD') !!}
                            <span class="eye">Left</span>{!! $rxInput('os_sph', '0.25', 'Left SPH', 'Plano') !!}{!! $rxInput('os_cyl', '0.25', 'Left CYL') !!}{!! $rxInput('os_axis', '1', 'Left AXIS') !!}{!! $rxInput('os_add', '0.25', 'Left ADD') !!}
                        </div>
                        @foreach(['od_sph', 'od_cyl', 'od_axis', 'od_add', 'os_sph', 'os_cyl', 'os_axis', 'os_add'] as $field)@error($field)<p class="err">{{ str_replace(['OD', 'OS'], ['Right', 'Left'], strtoupper(str_replace('_', ' ', $field))) }}: {{ $message }}</p>@enderror @endforeach
                        <div class="rxfoot">
                            <label class="f"><span>PD (mm)</span><input name="pd" type="number" step="0.5" value="{{ old('pd') }}" inputmode="decimal">@error('pd')<small class="err">{{ $message }}</small>@enderror</label>
                            <label class="f"><span>Lens type</span><select name="lens_type">@foreach(['' => 'Not sure', 'Single vision' => 'Single vision', 'Bifocal' => 'Bifocal', 'Progressive' => 'Progressive', 'Reading' => 'Reading'] as $value => $label)<option value="{{ $value }}" @selected(old('lens_type') === $value)>{{ $label }}</option>@endforeach</select></label>
                            <button type="button" class="btn outline" id="check-lenses" data-url="{{ route('order-page.lenses', $partner->page_token) }}">Check lenses</button>
                        </div>
                        <input type="hidden" name="lens_choice" id="lens-choice" value="{{ old('lens_choice') }}">
                        <input type="hidden" name="lens_choice_label" id="lens-choice-label" value="{{ old('lens_choice_label') }}">
                        <div class="lenses" id="lens-results" aria-live="polite"></div>
                        <div class="picked" id="lens-picked" @if(! old('lens_choice')) hidden @endif><span>Lens picked: <b id="lens-picked-label">{{ old('lens_choice_label') }}</b></span><button type="button" class="link" id="lens-unpick">Remove</button></div>
                        @error('lens_choice')<p class="err">{{ $message }}</p>@enderror

                        <div class="grid g3" style="margin-top:12px">
                            <label class="f"><span>Frame</span><select name="frame_choice" id="frame-choice">@foreach(\App\Models\OpticalOrderRequest::FRAME_CHOICES as $value => $label)<option value="{{ $value }}" @selected(old('frame_choice', 'patient_chooses') === $value)>{{ $label }}</option>@endforeach</select></label>
                            <label class="f"><span>Urgency</span><select name="urgency"><option value="normal">Normal</option><option value="urgent" @selected(old('urgency') === 'urgent')>Urgent</option></select></label>
                            <label class="f wide" id="frame-notes" @if(old('frame_choice') !== 'own_frame') hidden @endif><span>Patient's frame</span><input name="frame_notes" value="{{ old('frame_notes') }}" placeholder="Brand, model, colour or condition" maxlength="1000"></label>
                        </div>

                        <details class="more" @if($openNotes) open @endif>
                            <summary>+ Add notes</summary>
                            <label class="f" style="margin-top:8px"><span>Notes for {{ $optical }}</span><textarea name="notes" rows="2" maxlength="2000" placeholder="e.g. photochromic, blue filter, 1.67 index, or anything else">{{ old('notes', old('lens_notes')) }}</textarea></label>
                        </details>
                    </form>
                @endif
            </section>
        @endif
        <p class="hint" style="text-align:center">Keep this link and PIN within your clinic. {{ $optical }} can change them at any time.</p>
    </main>

    @if($signedIn && $taking)
        <div class="sendbar" data-panel="send" @if($startTab !== 'send') hidden @endif><div class="wrap">
            <span class="hint" style="margin:0">{{ $optical }} prices the job and bills your clinic as usual.</span>
            <button class="btn primary" type="submit" form="order-form">Send order</button>
        </div></div>
    @endif

    @if($signedIn)
    <script>
    (function () {
        // Tabs: Send order / Your orders.
        var tabs = document.querySelectorAll('.tab');
        function show(name) {
            tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.tab === name ? 'true' : 'false'); });
            document.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== name; });
        }
        tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab); }); });

        var change = document.getElementById('sender-change');
        if (change) change.addEventListener('click', function () {
            document.getElementById('sender-line').hidden = true;
            var field = document.getElementById('sender-field'); field.hidden = false; field.querySelector('input').focus();
        });

        var frame = document.getElementById('frame-choice');
        if (frame) frame.addEventListener('change', function () { document.getElementById('frame-notes').hidden = frame.value !== 'own_frame'; });

        // Check lenses: which lenses fit this prescription, in stock or to order in, and the price.
        var button = document.getElementById('check-lenses');
        if (!button) return;
        var form = document.getElementById('order-form'), results = document.getElementById('lens-results');
        var choice = document.getElementById('lens-choice'), choiceLabel = document.getElementById('lens-choice-label');
        var picked = document.getElementById('lens-picked'), pickedLabel = document.getElementById('lens-picked-label');
        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
        function pick(key, label) {
            choice.value = key || ''; choiceLabel.value = label || '';
            pickedLabel.textContent = label || ''; picked.hidden = !key;
            results.querySelectorAll('tr.pick').forEach(function (r) { r.classList.toggle('on', r.dataset.key === key); var i = r.querySelector('input'); if (i) i.checked = r.dataset.key === key; });
        }
        document.getElementById('lens-unpick').addEventListener('click', function () { pick('', ''); });
        // A changed prescription needs a new check: the pick no longer applies.
        form.querySelectorAll('[data-rx]').forEach(function (input) {
            input.addEventListener('input', function () {
                if (choice.value || results.innerHTML) { pick('', ''); results.innerHTML = '<p class="note">Prescription changed: check lenses again.</p>'; }
            });
        });
        button.addEventListener('click', function () {
            var body = new FormData();
            ['od_sph', 'od_cyl', 'od_axis', 'od_add', 'os_sph', 'os_cyl', 'os_axis', 'os_add'].forEach(function (n) { body.append(n, form.elements[n].value); });
            button.disabled = true; button.textContent = 'Checking…';
            fetch(button.dataset.url, { method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
                .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
                .then(function (res) {
                    var data = res.data;
                    if (res.status === 401) { window.location.reload(); return; }
                    if (res.status === 422) { results.innerHTML = '<p class="err">' + esc(Object.values(data.errors || {})[0] || data.message) + '</p>'; return; }
                    if (res.status !== 200) { results.innerHTML = '<p class="err">Could not check lenses just now. You can still send the order.</p>'; return; }
                    if (!data.options.length) { pick('', ''); results.innerHTML = '<p class="note">' + esc(data.message) + '</p>'; return; }
                    var html = '<table><thead><tr><th></th><th>Lens</th><th>Right</th><th>Left</th>' + (data.prices ? '<th class="r">Per pair</th>' : '') + '</tr></thead><tbody>';
                    data.options.forEach(function (o) {
                        var cell = function (v) { return '<td class="' + (v === 'In stock' ? 'stk' : 'ord') + '">' + esc(v) + '</td>'; };
                        html += '<tr class="pick" data-key="' + esc(o.key) + '" data-label="' + esc(o.label) + '"><td><input type="radio" name="lens_pick" aria-label="Pick ' + esc(o.label) + '"></td><td>' + esc(o.label) + '</td>' + cell(o.od) + cell(o.os)
                            + (data.prices ? '<td class="r"><b>' + esc(data.currency) + ' ' + Number(o.price).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</b></td>' : '') + '</tr>';
                    });
                    html += '</tbody></table><p class="note">Tap a lens to pick it (optional). ' + (data.prices ? 'Prices are estimates after your agreed discount; frame and fitting are extra. ' : '')
                        + '"Order in" takes a few extra days. The optical centre confirms the lens and the price.</p>';
                    results.innerHTML = html;
                    results.querySelectorAll('tr.pick').forEach(function (r) { r.addEventListener('click', function () { pick(r.dataset.key, r.dataset.label); }); });
                    if (choice.value) pick(data.options.some(function (o) { return o.key === choice.value; }) ? choice.value : '', choiceLabel.value);
                })
                .catch(function () { results.innerHTML = '<p class="err">Could not check lenses just now. You can still send the order.</p>'; })
                .finally(function () { button.disabled = false; button.textContent = 'Check lenses'; });
        });
    })();
    </script>
    @endif
</body>
</html>
