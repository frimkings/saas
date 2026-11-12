<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic scans, phase 2: scan orders and their scans. The money is a normal clinic sale
 * (sale_id); the order tracks who referred the patient and where the scans are up to.
 * See docs/diagnostic-scans-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('order_number', 30);
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('source', 10); // partner | internal | self
            $table->foreignId('scan_partner_clinic_id')->nullable()->constrained('scan_partner_clinics')->restrictOnDelete();
            $table->foreignId('scan_partner_doctor_id')->nullable()->constrained('scan_partner_doctors')->nullOnDelete();
            $table->string('referrer_note')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete(); // internal: the doctor
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('status', 12)->default('registered'); // registered | scanned | reported | sent | cancelled
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('share_consent')->default(false);
            $table->foreignId('consent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('results_sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['clinic_id', 'order_number']);
            $table->index(['clinic_id', 'status', 'created_at']);
            $table->index(['scan_partner_clinic_id', 'created_at']);
        });

        Schema::create('scan_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('scan_order_id')->constrained('scan_orders')->cascadeOnDelete();
            $table->foreignId('diagnostic_scan_id')->constrained('diagnostic_scans')->restrictOnDelete();
            $table->string('scan_name'); // as it was ordered, if the catalogue name changes later
            $table->string('eye', 2)->nullable(); // OD | OS | OU, for scans priced per eye
            $table->unsignedSmallInteger('quantity')->default(1); // eyes charged: 1, or 2 for OU
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_order_items');
        Schema::dropIfExists('scan_orders');
    }
};
