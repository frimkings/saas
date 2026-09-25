<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\RoleDashboardResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ForcedPasswordChangeController extends Controller
{
    public function edit()
    {
        abort_unless(request()->user()->must_change_password, 404);

        return view('auth.force-password-change');
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->must_change_password, 404);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'last_password_changed_at' => now(),
        ])->save();

        $request->session()->regenerate();

        if ($request->user()->is_platform_admin
            && $request->user()->clinics()->wherePivot('status', 'active')->doesntExist()) {
            $request->session()->put('workspace_mode', 'platform');

            return redirect()->route('platform.dashboard');
        }

        return redirect()->to(app(RoleDashboardResolver::class)->url($request->user()))
            ->with('status', 'Password changed successfully.');
    }
}
