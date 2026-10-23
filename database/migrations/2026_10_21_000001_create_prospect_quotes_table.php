<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations and pro-forma invoices for businesses that are not on the platform yet. Kept apart from
 * platform_invoices: a quote is not revenue, has no clinic until it is accepted, and never touches billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('document_type', 12)->default('quotation');
            $table->foreignId('pricing_request_id')->nullable()->constrained('pricing_requests')->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->string('product', 20)->nullable();
            $table->string('business_name', 150);
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('billing_interval', 10)->default('monthly');
            $table->string('currency', 3)->default('GHS');
            $table->json('lines');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->date('issued_on');
            $table->date('valid_until');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('status', 12)->default('draft')->index();
            $table->string('token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_quotes');
    }
};
