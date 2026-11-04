<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drugs a doctor prescribes for the patient to buy at a pharmacy: not stocked here, or out of stock.
 * Not cart items: never billed, dispensed or counted in stock. They print on the prescription slip
 * and feed the "prescribed but not stocked" report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outside_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            // Set when the drug is in the product list but was out of stock.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('prescribed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 200);
            $table->string('eye', 30)->nullable();
            $table->string('frequency', 50)->nullable();
            $table->unsignedSmallInteger('duration_value')->nullable();
            $table->string('duration_unit', 20)->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'patient_id', 'created_at'], 'outside_rx_patient_date_index');
            $table->index(['clinic_id', 'branch_id', 'created_at'], 'outside_rx_branch_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outside_prescriptions');
    }
};
