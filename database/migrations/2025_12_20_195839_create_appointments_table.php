<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('appointments', function (Blueprint $table) {
          $table->id();
    $table->foreignId('patient_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained(); // The doctor/staff who created it
    $table->string('title'); // e.g., "Check-up", "Consultation"
    $table->text('notes')->nullable();
    $table->dateTime('scheduled_at');
    $table->string('status')->default('scheduled'); // scheduled, completed, cancelled
    $table->softDeletes();
    $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('appointments');
    }
};
