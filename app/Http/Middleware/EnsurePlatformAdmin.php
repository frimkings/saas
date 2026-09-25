<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless((bool) $request->user()?->is_platform_admin, 403, 'Platform administrator access is required.');
        $hasClinics = $request->user()->clinics()->where('clinics.status', 'active')->wherePivot('status', 'active')->exists();
        if ($hasClinics && $request->session()->get('workspace_mode') !== 'platform') {
            return redirect()->route('tenant.mode.select');
        }
        // An admin with no clinic lands here without a mode (e.g. after their clinic was deleted).
        // Record it, or the page's Livewire requests are resolved as clinic requests and fail with 403.
        $request->session()->put('workspace_mode', 'platform');
        return $next($request);
    }
}
