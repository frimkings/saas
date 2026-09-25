<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
            'workspace' => ['nullable', 'in:auto,platform,clinic'],
            'remember_workspace' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate()
    {
        $this->ensureIsNotRateLimited();

        $login = $this->input('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        if (! Auth::attempt([$field => $login, 'password' => $this->input('password')], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        $isPlatformOnly = (bool) Auth::user()->is_platform_admin
            && Auth::user()->clinics()->wherePivot('status', 'active')->doesntExist();

        if (config('tenancy.enabled') && ! $isPlatformOnly && ! Auth::user()->clinics()
            ->where('clinics.status', 'active')->wherePivot('status', 'active')->exists()) {
            Auth::logout();
            throw ValidationException::withMessages([
                'login' => 'Your clinic membership is inactive. Contact your clinic administrator.',
            ]);
        }

        if (config('tenancy.enabled') && ! $isPlatformOnly) {
            $context = app(\App\Support\Tenancy\TenantContext::class)->resolveFor(Auth::user());
            app(\App\Support\Tenancy\BranchRoleManager::class)
                ->hydrate(Auth::user(), $context->requireBranch());
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited()
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     *
     * @return string
     */
    public function throttleKey()
    {
        return Str::lower($this->input('login')).'|'.$this->ip();
    }
}
