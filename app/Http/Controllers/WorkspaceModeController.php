<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class WorkspaceModeController extends Controller
{
    public function select(Request $request)
    {
        abort_unless($request->user()->is_platform_admin, 403);
        $clinicCount = $request->user()->clinics()->where('clinics.status', 'active')->wherePivot('status', 'active')->count();
        if ($clinicCount === 0) {
            $request->session()->put('workspace_mode', 'platform');
            return redirect()->route('platform.dashboard');
        }
        return view('tenant.select-workspace-mode', compact('clinicCount'));
    }

    public function switch(Request $request)
    {
        abort_unless($request->user()->is_platform_admin, 403);
        $validated = $request->validate([
            'mode' => ['required', 'in:platform,clinic'],
            'remember_workspace' => ['nullable', 'boolean'],
        ]);
        $request->session()->put('workspace_mode', $validated['mode']);

        if ($request->boolean('remember_workspace')) {
            $request->user()->forceFill(['preferred_workspace' => $validated['mode']])->save();
        }

        if ($validated['mode'] === 'platform') {
            app(TenantContext::class)->clear();
            $request->session()->forget([
                config('tenancy.session_keys.clinic'),
                config('tenancy.session_keys.branch'),
            ]);
            return redirect()->route('platform.dashboard');
        }

        abort_unless($request->user()->clinics()->where('clinics.status', 'active')
            ->wherePivot('status', 'active')->exists(), 403);

        return redirect()->route('tenant.clinic.select');
    }
}
