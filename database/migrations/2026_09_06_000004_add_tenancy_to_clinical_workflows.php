<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'cashier_patient_clearances',
        'consultations',
        'refractions',
        'referrals',
        'appointments',
        'online_bookings',
        'lens_orders',
        'patient_documents',
        'consultation_notes',
    ];

    public function up(): void
    {
        $clinicId = DB::connection()->pretending()
            ? 1
            : (DB::table('clinics')->where('status', 'active')->orderBy('id')->value('id')
                ?? DB::table('clinics')->orderBy('id')->value('id'));
        $branchId = DB::connection()->pretending()
            ? 1
            : DB::table('branches')->where('clinic_id', $clinicId)->orderByDesc('is_default')->orderBy('id')->value('id');

        if (! $clinicId || ! $branchId) {
            throw new \RuntimeException('A clinic and default branch are required before clinical workflows can be backfilled.');
        }

        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained('branches')->restrictOnDelete();
            });

            DB::table($tableName)->whereNull('clinic_id')->update([
                'clinic_id' => $clinicId,
                'branch_id' => $branchId,
            ]);

            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['clinic_id', 'branch_id']);
            });
        }

        Schema::table('cashier_patient_clearances', function (Blueprint $table) {
            // The legacy unique index also supports the patient foreign key on MySQL.
            $table->index('patient_id', 'clearances_patient_id_index');
            $table->dropUnique('cashier_patient_clearances_patient_id_clearance_date_unique');
            $table->unique(
                ['clinic_id', 'branch_id', 'patient_id', 'clearance_date'],
                'clearances_tenant_patient_date_unique'
            );
        });

        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropUnique('lens_orders_order_id_unique');
            $table->unique(['clinic_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'order_id']);
            $table->unique('order_id');
        });
        Schema::table('cashier_patient_clearances', function (Blueprint $table) {
            $table->dropUnique('clearances_tenant_patient_date_unique');
            $table->unique(['patient_id', 'clearance_date']);
            $table->dropIndex('clearances_patient_id_index');
        });

        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['clinic_id', 'branch_id']);
                $table->dropConstrainedForeignId('branch_id');
                $table->dropConstrainedForeignId('clinic_id');
            });
        }
    }
};
