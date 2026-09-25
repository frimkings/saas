<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('bill_status', 20)->default('finalized')->after('payment_status');
            $table->timestamp('finalized_at')->nullable()->after('bill_status');
            $table->index(['patient_id', 'bill_status'], 'sales_patient_bill_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_patient_bill_status_index');
            $table->dropColumn(['bill_status', 'finalized_at']);
        });
    }
};
