<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * optical_partner_changes: a request to move a partner job entered under the wrong partner
     * clinic to the right one. The front desk asks; a manager approves (the job moves) or rejects.
     * A manager's own change is recorded here as approved by them. Kept for good.
     */
    public function up(): void
    {
        Schema::create('optical_partner_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lens_order_id')->constrained('lens_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('from_partner_clinic_id')->nullable();
            $table->string('from_partner_name')->nullable();
            $table->foreignId('to_partner_clinic_id')->constrained('optical_partner_clinics')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['lens_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_partner_changes');
    }
};
