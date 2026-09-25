<x-guest-layout>
@php
    $settings = \App\Models\Setting::getSettings();
    $clinicName = $settings->clinic_name ?? config('app.name', 'Eye Clinic');
    $logoUri = $settings->logoDataUri();
@endphp

<style>
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

    .password-tips { margin-top: 2.5rem; width: 100%; max-width: 300px; }
    .password-tips-title { font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.75rem; }
    .tip-item { display: flex; align-items: center; gap: 0.6rem; font-size: 0.8rem; color: rgba(255,255,255,0.65); margin-bottom: 0.5rem; }
    .tip-item svg { width: 14px; height: 14px; stroke: #14d9c4; fill: none; stroke-width: 2; flex-shrink: 0; }

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

    .account-chip { display: flex; align-items: center; gap: 0.75rem; background: #fff; border: 1.5px solid #e5e7eb; border-radius: 0.625rem; padding: 0.75rem 1rem; margin-bottom: 1.5rem; }
    .account-avatar { width: 36px; height: 36px; background: linear-gradient(135deg,#0f3460,#0d7377); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .account-avatar svg { width: 18px; height: 18px; stroke: white; fill: none; stroke-width: 1.8; }
    .account-email { font-size: 0.9rem; font-weight: 600; color: #111827; }
    .account-label { font-size: 0.75rem; color: #6b7280; }

    .form-title { font-size: 1.5rem; font-weight: 800; color: #111827; margin-bottom: 0.4rem; }
    .form-subtitle { font-size: 0.9rem; color: #6b7280; margin-bottom: 1.75rem; }

    .field-group { margin-bottom: 1.25rem; }
    .field-label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .field-wrap { position: relative; }
    .field-icon { position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; stroke: #9ca3af; fill: none; stroke-width: 1.8; pointer-events: none; transition: stroke 0.2s; }
    .field-toggle { position: absolute; right: 0.875rem; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; stroke: #9ca3af; fill: none; stroke-width: 1.8; cursor: pointer; background: none; border: none; padding: 0; transition: stroke 0.2s; }
    .field-toggle:hover { stroke: #0d7377; }
    .field-input { width: 100%; padding: 0.75rem 2.75rem; border: 1.5px solid #e5e7eb; border-radius: 0.625rem; font-size: 0.95rem; color: #111827; background: #fff; outline: none; transition: border-color 0.2s, box-shadow 0.2s; box-sizing: border-box; }
    .field-input:focus { border-color: #0d7377; box-shadow: 0 0 0 3px rgba(13,115,119,0.12); }
    .field-wrap:focus-within .field-icon { stroke: #0d7377; }

    .strength-bar { height: 4px; border-radius: 2px; background: #e5e7eb; margin-top: 0.5rem; overflow: hidden; }
    .strength-fill { height: 100%; border-radius: 2px; transition: width 0.3s, background 0.3s; width: 0%; }
    .strength-label { font-size: 0.75rem; margin-top: 0.3rem; color: #6b7280; }

    .btn-primary { width: 100%; padding: 0.825rem 1.5rem; background: linear-gradient(135deg,#0f3460 0%,#0d7377 100%); color: #fff; font-size: 0.95rem; font-weight: 700; border: none; border-radius: 0.625rem; cursor: pointer; letter-spacing: 0.04em; transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s; box-shadow: 0 4px 14px rgba(13,115,119,0.35); }
    .btn-primary:hover { opacity: 0.92; transform: translateY(-1px); }

    .alert-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.875rem; color: #b91c1c; }
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
        <div class="brand-tagline">Choose a strong password to keep your account secure.</div>

        <div class="password-tips">
            <div class="password-tips-title">Password Tips</div>
            <div class="tip-item">
                <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>
                At least 6 characters long
            </div>
            <div class="tip-item">
                <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>
                Mix uppercase &amp; lowercase letters
            </div>
            <div class="tip-item">
                <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>
                Include numbers or symbols
            </div>
            <div class="tip-item">
                <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>
                Avoid common words or dates
            </div>
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

            <a href="{{ route('password.request') }}" class="back-link">
                <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back
            </a>

            <div class="step-badge">
                <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Step 2 of 2 — Set New Password
            </div>

            <div class="form-title">Reset Password</div>
            <div class="form-subtitle">Create a new password for your account.</div>

            {{-- Account chip --}}
            <div class="account-chip">
                <div class="account-avatar">
                    <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <div>
                    <div class="account-email">{{ $email }}</div>
                    <div class="account-label">Verified account</div>
                </div>
            </div>

            @if ($errors->any())
                <div class="alert-error">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('password.offline.update') }}">
                @csrf

                {{-- New Password --}}
                <div class="field-group">
                    <label for="password" class="field-label">New Password</label>
                    <div class="field-wrap">
                        <input id="password" class="field-input" type="password" name="password"
                            required minlength="6" placeholder="Min. 6 characters" oninput="checkStrength(this.value)">
                        <svg class="field-icon" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        <button type="button" class="field-toggle" onclick="toggleVisibility('password', this)" title="Show/hide password">
                            <svg viewBox="0 0 24 24" id="eye-password"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                    <div class="strength-label" id="strength-label"></div>
                </div>

                {{-- Confirm Password --}}
                <div class="field-group">
                    <label for="password_confirmation" class="field-label">Confirm New Password</label>
                    <div class="field-wrap">
                        <input id="password_confirmation" class="field-input" type="password"
                            name="password_confirmation" required placeholder="Repeat your password">
                        <svg class="field-icon" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        <button type="button" class="field-toggle" onclick="toggleVisibility('password_confirmation', this)" title="Show/hide password">
                            <svg viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary">Set New Password</button>
            </form>

            <div class="form-footer">&copy; {{ date('Y') }} {{ $clinicName }}. All rights reserved.</div>
        </div>
    </div>

</div>

<script>
function toggleVisibility(fieldId, btn) {
    const input = document.getElementById(fieldId);
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.style.stroke = input.type === 'text' ? '#0d7377' : '#9ca3af';
}

function checkStrength(val) {
    const fill = document.getElementById('strength-fill');
    const label = document.getElementById('strength-label');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { w: '0%',   bg: '',        text: '' },
        { w: '25%',  bg: '#ef4444', text: 'Weak' },
        { w: '50%',  bg: '#f59e0b', text: 'Fair' },
        { w: '75%',  bg: '#3b82f6', text: 'Good' },
        { w: '100%', bg: '#10b981', text: 'Strong' },
    ];
    fill.style.width = levels[score].w;
    fill.style.background = levels[score].bg;
    label.textContent = levels[score].text;
    label.style.color = levels[score].bg;
}
</script>
</x-guest-layout>
