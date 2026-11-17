<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linked optical centres, phase 2: trade quotes the clinic approves, the money each
 * job carries (mirrored for the clinic), clinic payments the optical centre confirms,
 * and remake requests. Both tables stay neutral: no tenant scope, every query filters
 * by the clinic on its own side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optical_order_requests', function (Blueprint $table) {
            $table->string('kind', 10)->default('order')->after('id');
            $table->unsignedBigInteger('remake_of_request_id')->nullable()->after('kind');
            $table->string('remake_reason', 30)->nullable()->after('remake_of_request_id');
            $table->decimal('quote_total', 12, 2)->nullable()->after('handled_by');
            $table->date('quote_valid_until')->nullable()->after('quote_total');
            $table->json('quote_lines')->nullable()->after('quote_valid_until');
            $table->timestamp('quoted_at')->nullable()->after('quote_lines');
            $table->timestamp('approved_at')->nullable()->after('quoted_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->text('decline_reason')->nullable()->after('approved_by');
            $table->decimal('order_total', 12, 2)->nullable()->after('decline_reason');
            $table->decimal('order_paid', 12, 2)->nullable()->after('order_total');
            $table->timestamp('order_cancelled_at')->nullable()->after('order_paid');
            $table->index('remake_of_request_id');
        });

        Schema::create('optical_link_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('optical_clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('optical_partner_clinic_id')->constrained('optical_partner_clinics')->cascadeOnDelete();
            $table->string('voucher_number', 30)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('allocations'); // request id => amount
            $table->string('status', 12)->default('pending'); // pending | confirmed | disputed | withdrawn
            $table->text('dispute_reason')->nullable();
            $table->unsignedBigInteger('expense_id')->nullable(); // sender side
            $table->unsignedBigInteger('optical_partner_payment_id')->nullable(); // optical side
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['sender_clinic_id', 'status']);
            $table->index(['optical_clinic_id', 'status']);
            $table->index('optical_partner_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_link_payments');
        Schema::table('optical_order_requests', function (Blueprint $table) {
            $table->dropIndex(['remake_of_request_id']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['kind', 'remake_of_request_id', 'remake_reason', 'quote_total', 'quote_valid_until', 'quote_lines', 'quoted_at',
                'approved_at', 'decline_reason', 'order_total', 'order_paid', 'order_cancelled_at']);
        });
    }
};
