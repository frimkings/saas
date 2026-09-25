<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\BranchRoleManager;
use App\Support\Tenancy\ClinicMembershipManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Support\Tenancy\RoleDashboardResolver;

class ClinicContextController extends Controller
{
    public function select(Request $request)
    {
        $clinics = $this->activeClinics($request);
        if ($clinics->count() === 1) {
            return $this->activate($request, (int) $clinics->first()->id, true);
        }

        return view('tenant.select-clinic', ['clinics' => $clinics]);
    }

    public function switch(Request $request)
    {
        $validated = $request->validate([
            'clinic_id' => ['required', 'integer'],
            'remember' => ['nullable', 'boolean'],
        ]);

        return $this->activate($request, (int) $validated['clinic_id'], $request->boolean('remember'));
    }

    private function activate(Request $request, int $clinicId, bool $remember)
    {
        $user = $request->user();
        if ($user->is_platform_admin) {
            $request->session()->put('workspace_mode', 'clinic');
        }
        $clinic = $this->activeClinics($request)->firstWhere('id', $clinicId);
        abort_unless($clinic, 403, 'You do not have access to this clinic.');

        $branches = $user->branches()->where('branches.clinic_id', $clinic->id)
            ->where('branches.is_active', true)->wherePivot('status', 'active');
        $authorizedIds = (clone $branches)->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        $branch = $branches->orderByDesc('branch_user.is_default')
            ->orderByDesc('branches.is_default')->orderBy('branches.id')->first();
        abort_unless($branch, 403, 'You do not have an active branch membership for this clinic.');

        if ($remember) {
            app(ClinicMembershipManager::class)->setDefault($user, $clinic);
        }

        $request->session()->migrate(true);
        foreach (['cart', 'pos_cart', 'selected_patient', 'active_consultation'] as $key) {
            $request->session()->forget($key);
        }
        $request->session()->put(config('tenancy.session_keys.clinic'), $clinic->id);
        $request->session()->put(config('tenancy.session_keys.branch'), $branch->id);
        app(TenantContext::class)->clear();
        app(TenantContext::class)->set($user, $clinic, $branch, $authorizedIds);
        app(BranchRoleManager::class)->hydrate($user, $branch);

        return redirect()->to(app(RoleDashboardResolver::class)->url($user));
    }

    private function activeClinics(Request $request)
    {
        return $request->user()->clinics()->where('clinics.status', 'active')
            ->wherePivot('status', 'active')->withCount([
                'branches' => fn ($query) => $query->where('is_active', true),
            ])->orderByDesc('clinic_user.is_default')->orderBy('clinics.name')->get();
    }

}
