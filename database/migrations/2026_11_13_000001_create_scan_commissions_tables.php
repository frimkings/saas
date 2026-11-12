<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic scans, phase 3: what referrers earn on paid scans, and the payouts that settle it.
 * Rows are never deleted: a refund voids an unpaid commission, or adds a negative row against a
 * paid one that the next payout nets off. See docs/diagnostic-scans-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('voucher_number', 30);
            $table->string('payee_type', 10); // partner | staff
            $table->foreignId('scan_partner_clinic_id')->nullable()->constrained('scan_partner_clinics')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('route', 10)->default('voucher'); // voucher | payroll
            $table->date('period_to')->nullable(); // commissions earned up to this day
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->unique(['clinic_id', 'voucher_number']);
        });

        Schema::create('scan_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('scan_order_id')->constrained('scan_orders')->restrictOnDelete();
            $table->foreignId('scan_order_item_id')->constrained('scan_order_items')->restrictOnDelete();
            $table->string('payee_type', 10); // partner | staff
            $table->foreignId('scan_partner_clinic_id')->nullable()->constrained('scan_partner_clinics')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('base_amount', 12, 2); // what the scan was paid, net of refunds, when this row was written
            $table->string('rate_type', 10); // percent | fixed, frozen from the first row
            $table->decimal('rate_value', 12, 2);
            $table->decimal('amount', 12, 2); // negative for a correction against a paid commission
            $table->string('kind', 12)->default('earned'); // earned | correction
            $table->date('earned_on');
            $table->string('status', 10)->default('accrued'); // accrued | paid | void
            $table->foreignId('scan_commission_payout_id')->nullable()->constrained('scan_commission_payouts')->restrictOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'payee_type', 'status']);
            $table->index(['scan_partner_clinic_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_commissions');
        Schema::dropIfExists('scan_commission_payouts');
    }
};
