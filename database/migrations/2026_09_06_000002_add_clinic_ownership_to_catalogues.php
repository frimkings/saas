<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'diagnoses',
        'drugs',
        'lens_options',
        'categories',
        'sms_templates',
        'referral_snippets',
        'insurers',
        'suppliers',
    ];

    public function up(): void
    {
        // Pretend mode records SQL without returning query results.
        $clinicId = DB::connection()->pretending()
            ? 1
            : (DB::table('clinics')->where('status', 'active')->orderBy('id')->value('id')
                ?? DB::table('clinics')->orderBy('id')->value('id'));

        if (! $clinicId) {
            throw new \RuntimeException('A clinic must exist before catalogue ownership can be backfilled.');
        }

        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('clinic_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('clinics')
                    ->restrictOnDelete();
            });

            DB::table($tableName)->whereNull('clinic_id')->update(['clinic_id' => $clinicId]);
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_name_unique');
            $table->unique(['clinic_id', 'name']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique('suppliers_name_unique');
            $table->unique(['clinic_id', 'name']);
        });

        Schema::table('lens_options', function (Blueprint $table) {
            $table->dropUnique('lens_options_family_display_name_unique');
            $table->unique(['clinic_id', 'family', 'display_name'], 'lens_options_clinic_family_name_unique');
        });

        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropUnique('sms_templates_key_unique');
            $table->unique(['clinic_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'name']);
            $table->unique('name');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'name']);
            $table->unique('name');
        });
        Schema::table('lens_options', function (Blueprint $table) {
            $table->dropUnique('lens_options_clinic_family_name_unique');
            $table->unique(['family', 'display_name']);
        });
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'key']);
            $table->unique('key');
        });

        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
            });
        }
    }
};
