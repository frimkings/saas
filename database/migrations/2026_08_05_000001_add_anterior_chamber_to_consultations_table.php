<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('consultations', 'acOD')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->text('acOD')->nullable()->after('pupilOS');
            });
        }

        if (!Schema::hasColumn('consultations', 'acOS')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->text('acOS')->nullable()->after('acOD');
            });
        }
    }

    public function down(): void
    {
        foreach (['acOD', 'acOS'] as $column) {
            if (Schema::hasColumn('consultations', $column)) {
                Schema::table('consultations', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
