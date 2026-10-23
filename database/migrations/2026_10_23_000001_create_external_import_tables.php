<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records brought into an existing clinic from another vendor's software (Cloudify first).
 * Every row an import creates is listed in external_import_rows so the import can be undone.
 * Payments from the old software go to patient_legacy_payments, never to sales: they are a
 * reference list on the patient record and stay out of bills, receipts and finance reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('source', 30);
            $table->string('status', 20)->default('analyzed');
            $table->string('stored_dir', 255)->nullable();
            $table->json('files')->nullable();
            $table->json('analysis')->nullable();
            $table->json('settings')->nullable();
            $table->json('result')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('current_step', 60)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'status']);
        });

        Schema::create('external_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_import_batch_id')->constrained('external_import_batches')->cascadeOnDelete();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('record_id');
            $table->index(['external_import_batch_id', 'table_name']);
        });

        Schema::create('patient_legacy_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('external_import_batch_id')->nullable()->constrained('external_import_batches')->nullOnDelete();
            $table->string('source', 30);
            $table->dateTime('paid_at');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 40)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('invoice_number', 60)->nullable();
            $table->text('notes')->nullable();
            $table->string('recorded_by', 150)->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'patient_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_legacy_payments');
        Schema::dropIfExists('external_import_rows');
        Schema::dropIfExists('external_import_batches');
    }
};
