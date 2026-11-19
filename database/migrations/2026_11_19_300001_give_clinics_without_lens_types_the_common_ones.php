<?php

use App\Support\ClinicStarterData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A refraction that needs glasses must have a lens type, and new clinics started with none:
     * on plans without the (then paid) lens catalogue they could not add any, so those refractions
     * could not be saved. Every clinic with no lens types gets the common ones. Clinics that already
     * have their own list are left alone.
     */
    public function up(): void
    {
        if (! Schema::hasTable('lens_options') || ! Schema::hasColumn('lens_options', 'clinic_id')) return;

        $have = DB::table('lens_options')->whereNotNull('clinic_id')->distinct()->pluck('clinic_id')->all();
        foreach (DB::table('clinics')->whereNotIn('id', $have)->pluck('id') as $clinicId) {
            ClinicStarterData::addLensTypes((int) $clinicId);
        }
    }

    public function down(): void
    {
        // Lens types a clinic now uses are not removed.
    }
};
