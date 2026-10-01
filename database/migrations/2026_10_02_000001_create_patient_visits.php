<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One receipt per visit: a patient's clinical sales for one clearance (the clearance service,
 * the doctor's prescription and anything reception added to it) form one visit, until its
 * bill is settled. A POS sale with no clearance is a visit of its own. Off for every clinic
 * until it switches "one receipt per visit" on; then the visit gets one receipt and one SMS
 * at the clinic's closing time instead of a receipt and an SMS per payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clearance_id')->nullable()->unique()->constrained('cashier_patient_clearances')->nullOnDelete();
            $table->string('visit_number', 30)->nullable()->unique();
            $table->date('opened_on');
            // The clinic day the closing-time SMS last covered, so each day is texted once.
            $table->date('sms_sent_for')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'opened_on']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('patient_visit_id')->nullable()->after('patient_id')->constrained()->nullOnDelete();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('visit_receipts_enabled')->default(false);
            $table->string('closing_time', 5)->default('17:00');
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn(['visit_receipts_enabled', 'closing_time']));
        Schema::table('sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('patient_visit_id'));
        Schema::dropIfExists('patient_visits');
    }
};
