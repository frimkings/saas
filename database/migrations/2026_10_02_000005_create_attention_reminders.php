<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Needs attention" reminders for staff (App\Services\Reminders\AttentionItems): appointments
 * coming up or missed today, spectacles due soon or past their promised date, and glasses not
 * collected. Each clinic sets the thresholds; staff mark an item done or snooze it, with a note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_appointment_hours')->default(2);
            $table->unsignedSmallInteger('reminder_due_days')->default(1);
            $table->unsignedSmallInteger('reminder_uncollected_days')->default(3);
            $table->unsignedSmallInteger('reminder_owner_uncollected_days')->default(14);
        });

        Schema::create('attention_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20);   // appointment | lens_order
            $table->unsignedBigInteger('subject_id');
            $table->string('rule', 30);           // which reminder it answers, e.g. order_late
            $table->string('action', 10);         // done | snoozed
            $table->timestamp('snoozed_until')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'rule'], 'attention_subject_rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attention_actions');
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn([
            'reminder_appointment_hours', 'reminder_due_days', 'reminder_uncollected_days', 'reminder_owner_uncollected_days',
        ]));
    }
};
