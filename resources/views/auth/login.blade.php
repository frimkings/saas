<x-guest-layout>
@php
    $settings = \App\Models\Setting::getSettings();
    $clinicName = $settings->clinic_name ?? config('app.name', 'Eye Clinic');
    $logoUri = $settings->logoDataUri();
@endphp

<style>
    .login-wrapper {
        min-height: 100vh;
        display: flex;
        font-family: 'Nunito', sans-serif;
    }

    /* ── Left branding panel ── */
    .brand-panel {
        display: none;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 3rem;
        background: linear-gradient(145deg, #0f3460 0%, #16213e 40%, #0d7377 100%);
        position: relative;
        overflow: hidden;
    }

    @media (min-width: 768px) {
        .brand-panel { display: flex; flex: 1; }
    }

    .brand-panel::before {
        content: '';
        position: absolute;
        width: 400px;
        height: 400px;
        border-radius: 50%;
        background: rgba(255,255,255,0.04);
        top: -120px;
        left: -120px;
    }

    .brand-panel::after {
        content: '';
        position: absolute;
        width: 300px;
        height: 300px;
        border-radius: 50%;
        background: rgba(255,255,255,0.04);
        bottom: -80px;
        right: -80px;
    }

    .brand-logo-wrap {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: rgba(255,255,255,0.12);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 2rem;
        border: 2px solid rgba(255,255,255,0.2);
        overflow: hidden;
        backdrop-filter: blur(4px);
    }

    .brand-logo-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .brand-icon {
        width: 70px;
        height: 70px;
        fill: none;
        stroke: rgba(255,255,255,0.85);
        stroke-width: 1.5;
    }

    .brand-name {
        font-size: 1.75rem;
        font-weight: 800;
        color: #ffffff;
        text-align: center;
        letter-spacing: 0.02em;
        line-height: 1.2;
        margin-bottom: 0.75rem;
        text-shadow: 0 2px 12px rgba(0,0,0,0.3);
    }

    .brand-tagline {
        font-size: 0.95rem;
        color: rgba(255,255,255,0.65);
        text-align: center;
        max-width: 280px;
        line-height: 1.6;
    }

    .brand-divider {
        width: 50px;
        height: 3px;
        background: linear-gradient(90deg, #14d9c4, #0d7377);
        border-radius: 2px;
        margin: 1.25rem auto;
    }

    .brand-features {
        margin-top: 2.5rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        width: 100%;
        max-width: 300px;
    }

    .brand-feature {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        color: rgba(255,255,255,0.7);
        font-size: 0.875rem;
    }

    .brand-feature svg {
        width: 18px;
        height: 18px;
        stroke: #14d9c4;
        flex-shrink: 0;
    }

    /* ── Right form panel ── */
    .form-panel {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 2rem 1.5rem;
        background: #f9fafb;
    }

    @media (min-width: 768px) {
        .form-panel { max-width: 480px; }
    }

    .form-card {
        width: 100%;
        max-width: 420px;
    }

    .form-header {
        margin-bottom: 2rem;
        text-align: center;
    }

    /* mobile-only logo */
    .mobile-logo {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    @media (min-width: 768px) {
        .mobile-logo { display: none; }
    }

    .mobile-logo-img {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #0d7377;
    }

    .mobile-logo-icon {
        width: 44px;
        height: 44px;
        background: linear-gradient(135deg, #0f3460, #0d7377);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .mobile-logo-icon svg {
        width: 24px;
        height: 24px;
        stroke: white;
        fill: none;
        stroke-width: 1.8;
    }

    .mobile-clinic-name {
        font-size: 1.1rem;
        font-weight: 700;
        color: #0f3460;
    }

    .form-title {
        font-size: 1.5rem;
        font-weight: 800;
        color: #111827;
        margin-bottom: 0.4rem;
    }

    .form-subtitle {
        font-size: 0.9rem;
        color: #6b7280;
    }

    /* inputs */
    .field-group {
        margin-bottom: 1.25rem;
    }

    .field-label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.4rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .field-wrap {
        position: relative;
    }

    .field-icon {
        position: absolute;
        left: 0.875rem;
        top: 50%;
        transform: translateY(-50%);
        width: 18px;
        height: 18px;
        stroke: #9ca3af;
        fill: none;
        stroke-width: 1.8;
        pointer-events: none;
        transition: stroke 0.2s;
    }

    .field-input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.75rem;
        border: 1.5px solid #e5e7eb;
        border-radius: 0.625rem;
        font-size: 0.95rem;
        color: #111827;
        background: #ffffff;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
        box-sizing: border-box;
    }

    .field-input.has-toggle {
        padding-right: 3rem;
    }

    .field-input:focus {
        border-color: #0d7377;
        box-shadow: 0 0 0 3px rgba(13,115,119,0.12);
    }

    .field-input:focus + .field-icon,
    .field-wrap:focus-within .field-icon {
        stroke: #0d7377;
    }

    .field-toggle {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        width: 2rem;
        height: 2rem;
        border: 0;
        background: transparent;
        color: #9ca3af;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        padding: 0;
    }

    .field-toggle:hover,
    .field-toggle:focus {
        color: #0d7377;
        outline: none;
    }

    .field-toggle svg {
        width: 19px;
        height: 19px;
        stroke: currentColor;
        fill: none;
        stroke-width: 1.8;
    }

    .field-toggle .eye-off {
        display: none;
    }

    .field-toggle.is-visible .eye-on {
        display: none;
    }

    .field-toggle.is-visible .eye-off {
        display: block;
    }

    .btn-login {
        width: 100%;
        padding: 0.825rem 1.5rem;
        background: linear-gradient(135deg, #0f3460 0%, #0d7377 100%);
        color: #ffffff;
        font-size: 0.95rem;
        font-weight: 700;
        border: none;
        border-radius: 0.625rem;
        cursor: pointer;
        letter-spacing: 0.04em;
        transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
        box-shadow: 0 4px 14px rgba(13,115,119,0.35);
    }

    .btn-login:hover {
        opacity: 0.92;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(13,115,119,0.4);
    }

    .btn-login:active {
        transform: translateY(0);
    }

    .btn-platform {
        width: 100%; margin-top: 0.75rem; padding: 0.75rem 1.5rem;
        background: #ffffff; color: #0f3460; font-size: 0.9rem; font-weight: 700;
        border: 1.5px solid #cbd5e1; border-radius: 0.625rem; cursor: pointer;
    }
    .btn-platform:hover { border-color: #0d7377; color: #0d7377; }
    .workspace-hint { margin: 0.65rem 0 0; color: #94a3b8; font-size: 0.75rem; text-align: center; }

    .remember-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.5rem;
    }

    .remember-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        color: #4b5563;
        cursor: pointer;
        user-select: none;
    }

    .remember-check {
        width: 16px;
        height: 16px;
        accent-color: #0d7377;
        cursor: pointer;
    }

    .forgot-link {
        font-size: 0.875rem;
        color: #0d7377;
        text-decoration: none;
        font-weight: 600;
    }

    .forgot-link:hover {
        text-decoration: underline;
    }

    /* alert/status */
    .alert-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        margin-bottom: 1.25rem;
        font-size: 0.875rem;
        color: #b91c1c;
    }

    .alert-success {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        margin-bottom: 1.25rem;
        font-size: 0.875rem;
        color: #15803d;
    }

    .form-footer {
        margin-top: 1.75rem;
        text-align: center;
        font-size: 0.8rem;
        color: #9ca3af;
    }
</style>

<div class="login-wrapper">

    {{-- ── Left branding panel ── --}}
    <div class="brand-panel">
        <div class="brand-logo-wrap">
            @if($logoUri)
                <img src="{{ $logoUri }}" alt="{{ $clinicName }} logo">
            @else
                {{-- Eye icon SVG --}}
                <svg class="brand-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
                    <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            @endif
        </div>

        <div class="brand-name">{{ $clinicName }}</div>
        <div class="brand-divider"></div>
        <div class="brand-tagline">Comprehensive eye care services delivered with precision and compassion.</div>

        <div class="brand-features">
            <div class="brand-feature">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Patient records &amp; history management</span>
            </div>
            <div class="brand-feature">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span>Appointment &amp; consultation scheduling</span>
            </div>
            <div class="brand-feature">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>Prescriptions &amp; pharmacy integration</span>
            </div>
        </div>
    </div>

    {{-- ── Right form panel ── --}}
    <div class="form-panel">
        <div class="form-card">

            {{-- Mobile-only logo --}}
            <div class="mobile-logo">
                @if($logoUri)
                    <img class="mobile-logo-img" src="{{ $logoUri }}" alt="{{ $clinicName }}">
                @else
                    <div class="mobile-logo-icon">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
                            <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                @endif
                <span class="mobile-clinic-name">{{ $clinicName }}</span>
            </div>

            <div class="form-header">
                <div class="form-title">Welcome back</div>
                <div class="form-subtitle">Sign in with your email or phone number</div>
            </div>

            {{-- Session status --}}
            @if (session('status'))
                <div class="alert-success">{{ session('status') }}</div>
            @endif

            {{-- Validation errors --}}
            @if ($errors->any())
                <div class="alert-error">
                    <ul style="margin:0; padding-left:1rem;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf

                {{-- Email or Phone --}}
                <div class="field-group">
                    <label for="login" class="field-label">Email or Phone Number</label>
                    <div class="field-wrap">
                        <input
                            id="login"
                            class="field-input"
                            type="text"
                            name="login"
                            value="{{ old('login') }}"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="Email address or phone number"
                        >
                        <svg class="field-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                    @error('login')
                        <p style="margin:.35rem 0 0;font-size:.8rem;color:#b91c1c;">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Password --}}
                <div class="field-group">
                    <label for="password" class="field-label">Password</label>
                    <div class="field-wrap">
                        <input
                            id="password"
                            class="field-input has-toggle"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                        >
                        <svg class="field-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        <button type="button"
                            class="field-toggle"
                            data-password-toggle="password"
                            aria-label="Show password"
                            title="Show/hide password">
                            <svg class="eye-on" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
                                <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <svg class="eye-off" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.58 10.58A2 2 0 0012 14a2 2 0 001.42-.58M9.88 5.2A9.7 9.7 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.51 10.51 0 01-3.01 4.43M6.61 6.61A10.7 10.7 0 002.458 12C3.732 16.057 7.522 19 12 19c1.25 0 2.445-.23 3.545-.648"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Remember me + Forgot password --}}
                <div class="remember-row">
                    <label class="remember-label">
                        <input class="remember-check" type="checkbox" name="remember" id="remember_me">
                        Remember me
                    </label>

                    @if (Route::has('password.request'))
                        <a class="forgot-link" href="{{ route('password.request') }}">Forgot password?</a>
                    @endif
                </div>

                <button type="submit" name="workspace" value="auto" class="btn-login">Sign In</button>
                <button type="submit" name="workspace" value="platform" class="btn-platform">Sign in to Platform Administration</button>
                <p class="workspace-hint">Platform access is confirmed securely after sign-in.</p>
            </form>

            <div class="form-footer">
                &copy; {{ date('Y') }} {{ $clinicName }}. All rights reserved.
            </div>
        </div>
    </div>

</div>
<script>
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-password-toggle]');
        if (!button) {
            return;
        }

        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) {
            return;
        }

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        button.classList.toggle('is-visible', isPassword);
        button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
</script>
</x-guest-layout>
