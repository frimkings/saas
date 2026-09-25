<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReferralsTable extends Migration
{
    public function up()
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referred_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('referral_to');
            $table->date('referral_date');
            $table->string('patient_name');
            $table->string('patient_age_sex')->nullable();
            $table->string('patient_contact')->nullable();
            $table->text('complaint')->nullable();
            $table->string('va_od')->nullable();
            $table->string('va_os')->nullable();
            $table->string('refraction')->nullable();
            $table->string('anterior_segment')->nullable();
            $table->string('posterior_segment')->nullable();
            $table->string('iop')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('reason_for_referral')->nullable();
            $table->text('management')->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('referrals');
    }
}
