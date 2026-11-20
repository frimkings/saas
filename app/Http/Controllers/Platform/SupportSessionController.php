<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Services\PlatformAuditService;
use App\Support\SupportSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * "Enter as support": a platform administrator steps into one clinic's own interface to inspect it,
 * without being a member of that clinic. The membership tables are never touched, so the admin stays
 * off the clinic's user list; the session just records which clinic is open (App\Support\SupportSession),
 * and ResolveTenantContext grants Super Admin in memory per request. Entering and leaving are audited.
 */
class SupportSessionController extends Controller
{
    public function enter(Request $request, Clinic $clinic)
    {
        abort_unless($request->user()?->is_platform_admin, 403);
        abort_unless($clinic->status === 'active', 422, 'Only an active clinic can be inspected.');
        abort_unless($clinic->branches()->where('is_active', true)->exists(), 422, 'This clinic has no active branch to inspect.');

        $request->session()->put(SupportSession::KEY, $clinic->id);
        $request->session()->put('workspace_mode', 'clinic');
        app(PlatformAuditService::class)->record('SUPPORT_SESSION_STARTED', $clinic->id, [], ['clinic' => $clinic->name], 'Platform support inspection');

        return redirect()->route('dashboard');
    }

    public function exit(Request $request)
    {
        $clinicId = SupportSession::clinicId();
        $request->session()->forget(SupportSession::KEY);
        $request->session()->put('workspace_mode', 'platform');
        app(TenantContext::class)->clear();
        $request->session()->forget([config('tenancy.session_keys.clinic'), config('tenancy.session_keys.branch')]);
        if ($clinicId) {
            app(PlatformAuditService::class)->record('SUPPORT_SESSION_ENDED', $clinicId, [], [], 'Platform support inspection ended');
        }

        return redirect()->route('platform.dashboard');
    }
}
