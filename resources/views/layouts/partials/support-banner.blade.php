@php($supportClinicId = \App\Support\SupportSession::clinicId())
@if($supportClinicId)
    @php($supportClinic = \App\Models\Clinic::find($supportClinicId))
    <div style="position:sticky;top:0;z-index:1200;background:#7c2d12;color:#fff;font-family:system-ui,sans-serif;font-size:13px;font-weight:700;padding:7px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,.18)">
        <span style="display:flex;align-items:center;gap:8px">
            <i class="fas fa-user-shield" aria-hidden="true"></i>
            Support session — inspecting {{ $supportClinic?->name ?? 'this clinic' }} as platform administrator. Changes you make are real.
        </span>
        <form method="POST" action="{{ route('support-session.exit') }}" style="margin:0">
            @csrf
            <button type="submit" style="background:#fff;color:#7c2d12;border:0;border-radius:6px;padding:4px 12px;font:inherit;font-weight:800;cursor:pointer">Exit support</button>
        </form>
    </div>
@endif
