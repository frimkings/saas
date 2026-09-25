<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        if (!Schema::hasColumn('cashier_patient_clearances', 'uuid')) {
            Schema::table('cashier_patient_clearances', function (Blueprint $table) {
                $table->uuid('uuid')->after('id')->nullable()->unique();
            });
        }
        if ($isSqlite) {
            DB::table('cashier_patient_clearances')->whereNull('uuid')->orderBy('id')->each(
                fn ($row) => DB::table('cashier_patient_clearances')->where('id', $row->id)->update(['uuid' => (string) \Illuminate\Support\Str::uuid()])
            );
        } else {
            DB::statement("UPDATE cashier_patient_clearances SET uuid = UUID() WHERE uuid IS NULL");
            DB::statement("ALTER TABLE cashier_patient_clearances MODIFY uuid CHAR(36) NOT NULL");
        }

        if (!Schema::hasColumn('patients', 'uuid')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->uuid('uuid')->after('id')->nullable()->unique();
            });
        }
        if ($isSqlite) {
            DB::table('patients')->whereNull('uuid')->orderBy('id')->each(
                fn ($row) => DB::table('patients')->where('id', $row->id)->update(['uuid' => (string) \Illuminate\Support\Str::uuid()])
            );
        } else {
            DB::statement("UPDATE patients SET uuid = UUID() WHERE uuid IS NULL");
            DB::statement("ALTER TABLE patients MODIFY uuid CHAR(36) NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('cashier_patient_clearances', fn (Blueprint $t) => $t->dropColumn('uuid'));
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('uuid'));
    }
};
