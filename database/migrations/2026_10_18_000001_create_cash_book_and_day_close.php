<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily balancing for each branch:
     * - cash_movements: money put into or taken out of a till or account by hand (banked,
     *   handed to the owner, a float top-up, the opening count, and what a count found over or
     *   short at day close). Kept for good; a mistake is cancelled, not deleted.
     * - payment_corrections: a payment recorded under the wrong method, moved to the right one
     *   once the owner approves.
     * - day_closes: a branch's day, counted and closed. After that the day's payments, voids
     *   and cash movements are settled; only the owner can reopen it.
     * - branches.till_float: what stays in the cash drawer overnight.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('till_float', 12, 2)->default(0);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // opening, banked, added, variance
            $table->string('payment_method', 40);
            // Signed: money in is positive, money out negative.
            $table->decimal('amount', 12, 2);
            $table->date('moved_on');
            $table->string('reference')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('day_close_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'payment_method', 'moved_on']);
        });

        Schema::create('payment_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('from_method', 40);
            $table->string('to_method', 40);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('day_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status', 20)->default('closed'); // closed, reopened
            // Per method: [method => ['expected' => x, 'counted' => y|null, 'variance' => z]]
            $table->json('methods');
            // Per cashier: [user_id => ['name' => ..., 'methods' => [method => amount], 'refunded' => x]]
            $table->json('cashiers');
            $table->decimal('variance', 12, 2)->default(0);
            $table->decimal('banked', 12, 2)->default(0);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_closes');
        Schema::dropIfExists('payment_corrections');
        Schema::dropIfExists('cash_movements');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('till_float');
        });
    }
};
