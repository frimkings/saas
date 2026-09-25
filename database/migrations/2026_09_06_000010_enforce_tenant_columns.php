<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $clinicOnly = [
        'diagnoses', 'drugs', 'lens_options', 'categories', 'sms_templates', 'referral_snippets',
        'insurers', 'suppliers', 'patients', 'products', 'expense_categories',
        'income_statement_templates', 'settings', 'sms_logs', 'report_deliveries',
        'app_notifications', 'staff_messages', 'audit_trails', 'login_logs',
        'system_health_statuses', 'login_logs_archive', 'audit_trails_archive', 'sms_logs_archive',
    ];

    private array $branchRequired = [
        'cashier_patient_clearances', 'consultations', 'refractions', 'referrals', 'appointments',
        'online_bookings', 'lens_orders', 'patient_documents', 'consultation_notes', 'sales',
        'sale_items', 'payment_transactions', 'sale_adjustments', 'refund_logs',
        'discount_approval_requests', 'clearance_revoke_logs', 'expenses', 'income_statement_entries',
        'income_statement_period_locks', 'insurance_claims', 'quotations', 'quotation_items',
        'carts', 'orders', 'stocks', 'stock_movements', 'purchase_orders', 'purchase_order_items',
    ];

    public function up(): void
    {
        foreach (array_unique(array_merge($this->clinicOnly, $this->branchRequired)) as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('clinic_id')->nullable(false)->change());
        }
        foreach ($this->branchRequired as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('branch_id')->nullable(false)->change());
        }
    }

    public function down(): void
    {
        foreach ($this->branchRequired as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('branch_id')->nullable()->change());
        }
        foreach (array_unique(array_merge($this->clinicOnly, $this->branchRequired)) as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('clinic_id')->nullable()->change());
        }
    }
};
