<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('domain')->nullable()->after('slug');
            $table->string('billing_email')->nullable()->after('default_currency');
            $table->string('billing_phone', 40)->nullable()->after('billing_email');
            $table->unsignedBigInteger('storage_limit_mb')->nullable()->after('billing_phone');
        });
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->decimal('annual_price', 12, 2)->default(0)->after('base_price');
            $table->string('currency', 3)->default('GHS')->after('annual_price');
            $table->unsignedInteger('included_users')->nullable()->after('included_branches');
            $table->unsignedInteger('storage_limit_mb')->nullable()->after('included_users');
            $table->unsignedInteger('sms_allowance')->nullable()->after('storage_limit_mb');
            $table->unsignedInteger('trial_days')->default(30)->after('sms_allowance');
            $table->unsignedInteger('grace_days')->default(7)->after('trial_days');
            $table->json('features')->nullable()->after('grace_days');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('features');
        });
        Schema::table('clinic_subscriptions', function (Blueprint $table) {
            $table->string('renewal_mode', 20)->default('manual')->after('status');
            $table->string('billing_interval', 20)->default('monthly')->after('renewal_mode');
            $table->text('notes')->nullable()->after('branch_limit_override');
            $table->text('change_reason')->nullable()->after('notes');
        });
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id(); $table->string('number')->unique();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('clinic_subscription_id')->nullable()->constrained('clinic_subscriptions')->nullOnDelete();
            $table->date('period_start'); $table->date('period_end'); $table->date('due_date');
            $table->decimal('subtotal', 12, 2); $table->decimal('tax', 12, 2)->default(0); $table->decimal('total', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0); $table->string('currency', 3)->default('GHS');
            $table->string('status', 20)->default('unpaid'); $table->text('notes')->nullable(); $table->timestamps();
            $table->index(['clinic_id', 'status']);
        });
        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id(); $table->foreignId('platform_invoice_id')->constrained('platform_invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2); $table->string('method', 30); $table->string('reference')->nullable();
            $table->timestamp('paid_at'); $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete(); $table->string('action', 80);
            $table->json('old_values')->nullable(); $table->json('new_values')->nullable(); $table->text('reason')->nullable();
            $table->ipAddress('ip_address')->nullable(); $table->timestamps(); $table->index(['clinic_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs'); Schema::dropIfExists('platform_payments'); Schema::dropIfExists('platform_invoices');
        Schema::table('clinic_subscriptions', fn (Blueprint $t) => $t->dropColumn(['renewal_mode','billing_interval','notes','change_reason']));
        Schema::table('subscription_plans', fn (Blueprint $t) => $t->dropColumn(['annual_price','currency','included_users','storage_limit_mb','sms_allowance','trial_days','grace_days','features','tax_rate']));
        Schema::table('clinics', fn (Blueprint $t) => $t->dropColumn(['domain','billing_email','billing_phone','storage_limit_mb']));
    }
};
