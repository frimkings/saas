<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21 Nov 2026:
 * - a linked clinic's owner can let the optical centre look up its patients (off until the owner turns it on);
 * - the optical centre can stop a job the clinic asked to cancel and still charge it, as a finished job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            $table->timestamp('patient_lookup_at')->nullable()->after('auto_approve_limit');
            $table->foreignId('patient_lookup_by')->nullable()->after('patient_lookup_at')->constrained('users')->nullOnDelete();
        });
        Schema::table('optical_order_requests', function (Blueprint $table) {
            $table->timestamp('cancel_charged_at')->nullable()->after('cancel_answered_at');
        });
    }

    public function down(): void
    {
        Schema::table('optical_order_requests', function (Blueprint $table) {
            $table->dropColumn('cancel_charged_at');
        });
        Schema::table('optical_partner_clinics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_lookup_by');
            $table->dropColumn('patient_lookup_at');
        });
    }
};
