<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('invoice_number', 100)->nullable()->after('notes');
            $table->date('invoice_date')->nullable()->after('invoice_number');
            $table->date('invoice_due_date')->nullable()->after('invoice_date');
            $table->decimal('invoice_amount', 10, 2)->nullable()->after('invoice_due_date');
            $table->decimal('paid_amount', 10, 2)->default(0)->after('invoice_amount');
            $table->string('payment_method', 30)->nullable()->after('paid_amount');
            $table->string('payment_reference', 100)->nullable()->after('payment_method');
            $table->date('paid_at')->nullable()->after('payment_reference');
            $table->enum('invoice_status', ['none', 'invoiced', 'partial', 'paid'])->default('none')->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_number', 'invoice_date', 'invoice_due_date', 'invoice_amount',
                'paid_amount', 'payment_method', 'payment_reference', 'paid_at', 'invoice_status',
            ]);
        });
    }
};
