<?php

use App\Support\Pricing\ClinicalSuitePlans;
use Illuminate\Database\Migrations\Migration;

/**
 * Standard Optical is the Optical Suite's only plan (18 Nov 2026): every other optical plan (optical shop only, or
 * clinic and optical together, e.g. the "Optical Shop" GHS 300 plan) is archived unless a clinic that is active now
 * is on it. Archived plans are not offered any more; their rows stay because subscriptions and invoices point at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        ClinicalSuitePlans::retireOtherOpticalPlans();
    }

    public function down(): void
    {
        // Which plans were on sale before is in the platform audit and retired_at; they are re-offered from the platform.
    }
};
