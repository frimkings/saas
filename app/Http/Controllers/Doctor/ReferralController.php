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
        $settings = Setting::getSettings();
        return view('pdf.referral-letter', compact('referral', 'settings'));
    }
}
