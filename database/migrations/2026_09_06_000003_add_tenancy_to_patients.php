<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
            throw new \RuntimeException('A clinic and default branch are required before patients can be backfilled.');
        }

        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('home_branch_id')->nullable()->after('clinic_id')->constrained('branches')->nullOnDelete();
        });

        DB::table('patients')->whereNull('clinic_id')->update([
            'clinic_id' => $clinicId,
            'home_branch_id' => $branchId,
        ]);

        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique('patients_pxnumber_unique');
            $table->unique(['clinic_id', 'pxnumber']);
            $table->index(['clinic_id', 'name']);
            $table->index(['clinic_id', 'contact']);
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['clinic_id', 'contact']);
            $table->dropIndex(['clinic_id', 'name']);
            $table->dropUnique(['clinic_id', 'pxnumber']);
            $table->unique('pxnumber');
            $table->dropConstrainedForeignId('home_branch_id');
            $table->dropConstrainedForeignId('clinic_id');
        });
    }
};
