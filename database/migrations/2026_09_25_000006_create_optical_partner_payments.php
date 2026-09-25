<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One payment from a partner clinic, split across the jobs it settles.
        Schema::create('optical_partner_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('optical_partner_clinic_id')->constrained('optical_partner_clinics')->restrictOnDelete();
            $table->string('receipt_number', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('optical_partner_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('optical_partner_payment_id');
            $table->foreign('optical_partner_payment_id', 'optical_partner_alloc_payment_fk')->references('id')->on('optical_partner_payments')->cascadeOnDelete();
            $table->foreignId('lens_order_id')->constrained('lens_orders')->restrictOnDelete();
            $table->unsignedBigInteger('payment_transaction_id')->nullable();
            $table->foreign('payment_transaction_id', 'optical_partner_alloc_txn_fk')->references('id')->on('payment_transactions')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_partner_payment_allocations');
        Schema::dropIfExists('optical_partner_payments');
    }
};
