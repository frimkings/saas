<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\CashierPatientClearance;

class ClearanceNoticeController extends Controller
{
    public function latest()
    {
        $latestClearance = CashierPatientClearance::with('patient:id,name,pxnumber')
            ->where('doctor_status', 0)
            ->whereDate('clearance_date', now()->toDateString())
            ->latest('id')
            ->first();

        return response()->json([
            'latest_id' => $latestClearance?->id,
            'pending_count' => CashierPatientClearance::where('doctor_status', 0)
                ->whereDate('clearance_date', now()->toDateString())
                ->count(),
            'patient_name' => $latestClearance?->patient?->name,
            'patient_number' => $latestClearance?->patient?->pxnumber,
        ]);
    }
}
