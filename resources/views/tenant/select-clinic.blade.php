<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.scripts')
    <title>Select Clinic</title>
    <style>
        body{margin:0;background:#07101f;color:#e8eef8;font-family:Arial,sans-serif;min-height:100vh;display:grid;place-items:center}.clinic-shell{width:min(920px,calc(100% - 32px));padding:35px 0}.clinic-head{text-align:center;margin-bottom:24px}.clinic-head h1{font-size:27px;margin:0 0 7px}.clinic-head p{color:#91a2ba}.clinic-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.clinic-card{display:block;background:#101b2e;border:1px solid #263650;border-radius:14px;padding:18px;color:#e8eef8;cursor:pointer;transition:.18s;text-align:left}.clinic-card:hover,.clinic-card:has(input:checked){border-color:#38bdf8;box-shadow:0 0 0 2px rgba(56,189,248,.15);transform:translateY(-2px)}.clinic-card input{position:absolute;opacity:0}.clinic-mark{width:42px;height:42px;border-radius:11px;background:#123d58;color:#38bdf8;display:grid;place-items:center;font-size:19px;font-weight:bold;margin-bottom:13px}.clinic-name{font-size:17px;font-weight:800}.clinic-meta{font-size:11px;color:#91a2ba;margin-top:6px}.clinic-default{color:#34d399;font-weight:bold}.clinic-actions{margin-top:20px;display:flex;align-items:center;justify-content:space-between;gap:12px}.remember{color:#aebbd0}.continue{background:#168dcc;border:0;color:#fff;font-weight:800;padding:11px 24px;border-radius:8px}.error{background:#471c2c;color:#ffc0ce;padding:10px;border-radius:8px;margin-bottom:12px}@media(max-width:600px){.clinic-actions{align-items:stretch;flex-direction:column}.continue{width:100%}}
    </style>
</head>
<body><main class="clinic-shell"><div class="clinic-head"><h1>Choose your clinic</h1><p>Your branch access and role will be loaded for the clinic you select.</p></div>
@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('tenant.clinic.switch') }}">@csrf
<div class="clinic-grid">@foreach($clinics as $clinic)<label class="clinic-card"><input type="radio" name="clinic_id" value="{{ $clinic->id }}" @checked($loop->first)><span class="clinic-mark">{{ strtoupper(substr($clinic->name,0,1)) }}</span><span class="clinic-name">{{ $clinic->name }}</span><span class="clinic-meta">{{ $clinic->branches_count }} active {{ Str::plural('branch',$clinic->branches_count) }} · {{ ucfirst($clinic->deployment_mode) }}</span>@if($clinic->pivot->is_default)<span class="clinic-meta clinic-default">Default clinic</span>@endif</label>@endforeach</div>
<div class="clinic-actions"><label class="remember"><input type="checkbox" name="remember" value="1" checked> Remember as my default clinic</label><button class="continue">Continue securely →</button></div></form></main></body></html>
