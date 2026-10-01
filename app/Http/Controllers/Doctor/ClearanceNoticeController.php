<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\CashierPatientClearance;
use App\Models\User;
use App\Services\ClinicAccessService;
use App\Support\OpticalMode;

/** Tells doctors when a patient has been cleared to see them today (polled via /pulse). */
class ClearanceNoticeController extends Controller
{
    /** The doctor routes' own checks: Doctor or Super Admin role, and a clinic (not optical-only) plan. */
    public static function mayPoll(?User $user): bool
    {
        return $user !== null && $user->hasRole(['Doctor', 'Super Admin'])
            && ! OpticalMode::opticalOnly()
            && app(ClinicAccessService::class)->access('clinical')['allowed'];
    }

    public function notice(): array
    {
        $today = CashierPatientClearance::where('doctor_status', 0)
            ->where('clearance_date', now()->toDateString());

        $latestClearance = (clone $today)->with('patient:id,name,pxnumber')->latest('id')->first();

        return [
            'latest_id' => $latestClearance?->id,
            'pending_count' => $latestClearance ? $today->count() : 0,
            'patient_name' => $latestClearance?->patient?->name,
            'patient_number' => $latestClearance?->patient?->pxnumber,
        ];
    }
}
