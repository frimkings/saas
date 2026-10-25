<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\Setting;

class ReferralController extends Controller
{
    public function printLetter(Referral $referral)
    {
        $referral->load('patient', 'referredBy');
        // Who printed it last, and when (the letter itself is unchanged).
        $referral->forceFill(['printed_by' => auth()->id(), 'printed_at' => now()])->saveQuietly();
        $settings = Setting::getSettings();
        return view('pdf.referral-letter', compact('referral', 'settings'));
    }
}
