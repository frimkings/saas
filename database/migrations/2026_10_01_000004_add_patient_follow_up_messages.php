<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic follow-up SMS: missed appointments, aftercare after glasses are collected, and
 * the doctor-set "next eye exam due" recall. Each "sent" column makes its message go once;
 * clinical_recall_sent_for remembers which due date was announced, so a new date is sent again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('missed_followup_sent_at')->nullable()->after('missed_at');
        });

        Schema::table('lens_orders', function (Blueprint $table) {
            $table->timestamp('aftercare_sent_at')->nullable()->after('collected_at');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->date('next_exam_due_on')->nullable()->after('recall_sms_sent_at');
            $table->date('clinical_recall_sent_for')->nullable()->after('next_exam_due_on');
            $table->index(['clinic_id', 'next_exam_due_on'], 'patients_clinic_next_exam_index');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('aftercare_sms_days')->default(5);
            $table->unsignedSmallInteger('clinical_recall_lead_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn(['aftercare_sms_days', 'clinical_recall_lead_days']));
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_clinic_next_exam_index');
            $table->dropColumn(['next_exam_due_on', 'clinical_recall_sent_for']);
        });
        Schema::table('lens_orders', fn (Blueprint $table) => $table->dropColumn('aftercare_sent_at'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('missed_followup_sent_at'));
    }
};
