<x-guest-layout>
@php
    $settings = \App\Models\Setting::getSettings();
    $clinicName = $settings->clinic_name ?? config('app.name', 'Eye Clinic');
    $logoUri = $settings->logoDataUri();
@endphp

<style>
    /* Reuse same base styles as login page */
    .login-wrapper { min-height: 100vh; display: flex; font-family: 'Nunito', sans-serif; }

    .brand-panel {
        display: none; flex-direction: column; justify-content: center; align-items: center;
        padding: 3rem; background: linear-gradient(145deg, #0f3460 0%, #16213e 40%, #0d7377 100%);
        position: relative; overflow: hidden;
    }
    @media (min-width: 768px) { .brand-panel { display: flex; flex: 1; } }
    .brand-panel::before { content: ''; position: absolute; width: 400px; height: 400px; border-radius: 50%; background: rgba(255,255,255,0.04); top: -120px; left: -120px; }
    .brand-panel::after  { content: ''; position: absolute; width: 300px; height: 300px; border-radius: 50%; background: rgba(255,255,255,0.04); bottom: -80px; right: -80px; }

    .brand-logo-wrap { width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,0.12); display: flex; align-items: center; justify-content: center; margin-bottom: 2rem; border: 2px solid rgba(255,255,255,0.2); overflow: hidden; }
    .brand-logo-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .brand-icon { width: 70px; height: 70px; fill: none; stroke: rgba(255,255,255,0.85); stroke-width: 1.5; }
    .brand-name { font-size: 1.75rem; font-weight: 800; color: #fff; text-align: center; margin-bottom: 0.75rem; text-shadow: 0 2px 12px rgba(0,0,0,0.3); }
    .brand-divider { width: 50px; height: 3px; background: linear-gradient(90deg, #14d9c4, #0d7377); border-radius: 2px; margin: 1.25rem auto; }
    .brand-tagline { font-size: 0.95rem; color: rgba(255,255,255,0.65); text-align: center; max-width: 280px; line-height: 1.6; }

    .lock-illustration { margin-top: 2.5rem; opacity: 0.15; }
    .lock-illustration svg { width: 140px; height: 140px; stroke: #fff; fill: none; stroke-width: 1; }

    .form-panel { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 2rem 1.5rem; background: #f9fafb; }
    @media (min-width: 768px) { .form-panel { max-width: 480px; } }
    .form-card { width: 100%; max-width: 420px; }

    .mobile-logo { display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-bottom: 1.5rem; }
    @media (min-width: 768px) { .mobile-logo { display: none; } }
    .mobile-logo-img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #0d7377; }
    .mobile-logo-icon { width: 44px; height: 44px; background: linear-gradient(135deg,#0f3460,#0d7377); border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .mobile-logo-icon svg { width: 24px; height: 24px; stroke: white; fill: none; stroke-width: 1.8; }
    .mobile-clinic-name { font-size: 1.1rem; font-weight: 700; color: #0f3460; }

    .back-link { display: inline-flex; align-items: center; gap: 0.4rem; color: #6b7280; font-size: 0.875rem; text-decoration: none; margin-bottom: 1.5rem; transition: color 0.2s; }
    .back-link:hover { color: #0d7377; }
    .back-link svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; }

    .step-badge { display: inline-flex; align-items: center; gap: 0.5rem; background: #e0f2f1; color: #0d7377; font-size: 0.8rem; font-weight: 700; padding: 0.35rem 0.85rem; border-radius: 2rem; margin-bottom: 1rem; }
    .step-badge svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }

    .form-title { font-size: 1.5rem; font-weight: 800; color: #111827; margin-bottom: 0.4rem; }
    .form-subtitle { font-size: 0.9rem; color: #6b7280; margin-bottom: 1.75rem; line-height: 1.5; }

    .field-group { margin-bottom: 1.25rem; }
    .field-label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .field-wrap { position: relative; }
    .field-icon { position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; stroke: #9ca3af; fill: none; stroke-width: 1.8; pointer-events: none; transition: stroke 0.2s; }
    .field-input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.75rem; border: 1.5px solid #e5e7eb; border-radius: 0.625rem; font-size: 0.95rem; color: #111827; background: #fff; outline: none; transition: border-color 0.2s, box-shadow 0.2s; box-sizing: border-box; }
    .field-input:focus { border-color: #0d7377; box-shadow: 0 0 0 3px rgba(13,115,119,0.12); }
    .field-wrap:focus-within .field-icon { stroke: #0d7377; }

    .btn-primary { width: 100%; padding: 0.825rem 1.5rem; background: linear-gradient(135deg,#0f3460 0%,#0d7377 100%); color: #fff; font-size: 0.95rem; font-weight: 700; border: none; border-radius: 0.625rem; cursor: pointer; letter-spacing: 0.04em; transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s; box-shadow: 0 4px 14px rgba(13,115,119,0.35); }
    .btn-primary:hover { opacity: 0.92; transform: translateY(-1px); }
    .btn-primary:active { transform: translateY(0); }

    .alert-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.875rem; color: #b91c1c; }
    .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.875rem; color: #15803d; }
    .form-footer { margin-top: 1.75rem; text-align: center; font-size: 0.8rem; color: #9ca3af; }
</style>

<div class="login-wrapper">

    {{-- Left branding panel --}}
    <div class="brand-panel">
        <div class="brand-logo-wrap">
            @if($logoUri)
                <img src="{{ $logoUri }}" alt="{{ $clinicName }}">
            @else
                <svg class="brand-icon" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            @endif
        </div>
        <div class="brand-name">{{ $clinicName }}</div>
        <div class="brand-divider"></div>
        <div class="brand-tagline">Account recovery without the need for email or internet connection.</div>

        <div class="lock-illustration">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <rect x="5" y="11" width="14" height="10" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 018 0v4"/>
                <circle cx="12" cy="16" r="1" fill="white"/>
            </svg>
        </div>
    </div>

    {{-- Right form panel --}}
    <div class="form-panel">
        <div class="form-card">

            {{-- Mobile logo --}}
            <div class="mobile-logo">
                @if($logoUri)
                    <img class="mobile-logo-img" src="{{ $logoUri }}" alt="{{ $clinicName }}">
                @else
                    <div class="mobile-logo-icon">
                        <svg viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </div>
                @endif
                <span class="mobile-clinic-name">{{ $clinicName }}</span>
            </div>

            <a href="{{ route('login') }}" class="back-link">
                <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Sign In
            </a>

            <div class="step-badge">
                <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Step 1 of 2 — Verify Account
            </div>

            <div class="form-title">Forgot your password?</div>
            <div class="form-subtitle">Enter your registered email address and we'll take you directly to the password reset screen — no email required.</div>

            @if ($errors->any())
                <div class="alert-error">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            {{-- Request submitted for the first time --}}
            @if(session('request_status') === 'submitted')
                <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:.625rem;padding:1rem 1.25rem;margin-bottom:1.25rem;">
                    <div style="display:flex;align-items:center;gap:.6rem;font-weight:700;color:#c2410c;margin-bottom:.4rem;">
                        <svg style="width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Request Submitted
                    </div>
                    <p style="font-size:.875rem;color:#9a3412;margin:0;line-height:1.5;">
                        Your password reset request has been submitted and is <strong>awaiting Super Admin approval</strong>.
                        Once approved, return here and enter your email to proceed.
                    </p>
                </div>
            @endif

            {{-- Already pending --}}
            @if(session('request_status') === 'pending')
                <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:.625rem;padding:1rem 1.25rem;margin-bottom:1.25rem;">
                    <div style="display:flex;align-items:center;gap:.6rem;font-weight:700;color:#1d4ed8;margin-bottom:.4rem;">
                        <svg style="width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Pending Approval
                    </div>
                    <p style="font-size:.875rem;color:#1e40af;margin:0;line-height:1.5;">
                        Your request is still <strong>under review</strong>. Please contact your Super Admin or check back later.
                        Once approved, enter your email again to set a new password.
                    </p>
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="field-group">
                    <label for="email" class="field-label">Email Address</label>
                    <div class="field-wrap">
                        <input id="email" class="field-input" type="email" name="email"
                            value="{{ old('email') }}" required autofocus placeholder="you@example.com">
                        <svg class="field-icon" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
                        </svg>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    @if(session('request_status') === 'pending')
                        Check Approval Status
                    @else
                        Find My Account
                    @endif
                </button>
            </form>

            <div class="form-footer">&copy; {{ date('Y') }} {{ $clinicName }}. All rights reserved.</div>
        </div>
    </div>

</div>
</x-guest-layout>
