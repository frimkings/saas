<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\BranchRoleManager;
use App\Support\Tenancy\RoleDashboardResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantContextController extends Controller
{
    public function switchBranch(Request $request): RedirectResponse
    {
        $validated = $request->validate(['branch_id' => ['required', 'integer']]);
        $activeClinicId = app(TenantContext::class)->requireClinic()->id;
        $branch = $request->user()->branches()
            ->where('branches.clinic_id', $activeClinicId)
            ->whereKey($validated['branch_id'])->where('branches.is_active', true)
            ->wherePivot('status', 'active')->firstOrFail();
        $clinicActive = $request->user()->clinics()->whereKey($branch->clinic_id)
            ->where('clinics.status', 'active')->wherePivot('status', 'active')->exists();
        abort_unless($clinicActive, 403);

        $request->session()->migrate(true);
        $request->session()->put(config('tenancy.session_keys.clinic'), $branch->clinic_id);
        $request->session()->put(config('tenancy.session_keys.branch'), $branch->id);
        foreach (['cart', 'pos_cart', 'selected_patient', 'active_consultation'] as $key) {
            $request->session()->forget($key);
        }

        $user = $request->user();
        $authorizedIds = $user->branches()->where('branches.clinic_id', $branch->clinic_id)
            ->where('branches.is_active', true)->wherePivot('status', 'active')
            ->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        app(TenantContext::class)->clear();
        app(TenantContext::class)->set($user, $branch->clinic, $branch, $authorizedIds);
        app(BranchRoleManager::class)->hydrate($user, $branch);

        return redirect()->to(app(RoleDashboardResolver::class)->url($user))
            ->with('status', 'Active branch changed to '.$branch->name.'.');
    }
}
