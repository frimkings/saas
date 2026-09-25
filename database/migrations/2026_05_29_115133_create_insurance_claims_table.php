<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_id')->nullable();
            $table->string('member_name')->nullable();
            $table->string('policy_number')->nullable();
            $table->decimal('claim_amount', 10, 2);
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'partially_approved', 'rejected', 'paid'])->default('draft');
            $table->date('submission_date')->nullable();
            $table->date('approval_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'status']);
            $table->index('status');
            $table->index('submission_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_claims');
    }
};
