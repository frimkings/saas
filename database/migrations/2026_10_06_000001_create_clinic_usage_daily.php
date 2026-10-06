<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per clinic per day: requests, data and server time (MeterClinicUsage) and stored rows (usage:snapshot-storage). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('actions')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('server_ms')->default(0);
            $table->unsignedBigInteger('db_ms')->default(0);
            $table->unsignedBigInteger('db_queries')->default(0);
            $table->unsignedBigInteger('stored_rows')->nullable();
            $table->timestamps();
            $table->unique(['clinic_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_usage_daily');
    }
};
