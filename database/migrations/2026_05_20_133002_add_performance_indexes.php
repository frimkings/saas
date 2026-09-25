<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // patients_created_at_index and clearances_status_date_index were added
        // by a partial prior run — skip them to avoid duplicate key errors.

        $this->addIfMissing('patients', 'patients_created_at_index', function (Blueprint $t) {
            $t->index('created_at', 'patients_created_at_index');
        });

        $this->addIfMissing('cashier_patient_clearances', 'clearances_status_date_index', function (Blueprint $t) {
            $t->index(['doctor_status', 'clearance_date'], 'clearances_status_date_index');
        });

        $this->addIfMissing('sales', 'sales_created_refunded_index', function (Blueprint $t) {
            $t->index(['created_at', 'is_refunded'], 'sales_created_refunded_index');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_created_at_index');
        });

        Schema::table('cashier_patient_clearances', function (Blueprint $table) {
            $table->dropIndex('clearances_status_date_index');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_created_refunded_index');
        });
    }

    private function addIfMissing(string $table, string $indexName, callable $callback): void
    {
        $exists = collect(Schema::getIndexes($table))
            ->pluck('name')
            ->contains($indexName);

        if (!$exists) {
            Schema::table($table, $callback);
        }
    }
};
