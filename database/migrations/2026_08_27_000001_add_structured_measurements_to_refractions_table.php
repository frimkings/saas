<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refractions', function (Blueprint $table) {
            $table->string('objective_method', 40)->nullable()->after('consultation_id');
            $table->decimal('objective_od_sphere', 5, 2)->nullable();
            $table->decimal('objective_od_cylinder', 5, 2)->nullable();
            $table->unsignedSmallInteger('objective_od_axis')->nullable();
            $table->string('objective_od_va', 20)->nullable();
            $table->decimal('objective_os_sphere', 5, 2)->nullable();
            $table->decimal('objective_os_cylinder', 5, 2)->nullable();
            $table->unsignedSmallInteger('objective_os_axis')->nullable();
            $table->string('objective_os_va', 20)->nullable();
            $table->text('objective_notes')->nullable();

            $table->decimal('subjective_od_sphere', 5, 2)->nullable();
            $table->decimal('subjective_od_cylinder', 5, 2)->nullable();
            $table->unsignedSmallInteger('subjective_od_axis')->nullable();
            $table->decimal('subjective_od_add', 5, 2)->nullable();
            $table->string('subjective_od_bcva', 20)->nullable();
            $table->decimal('subjective_os_sphere', 5, 2)->nullable();
            $table->decimal('subjective_os_cylinder', 5, 2)->nullable();
            $table->unsignedSmallInteger('subjective_os_axis')->nullable();
            $table->decimal('subjective_os_add', 5, 2)->nullable();
            $table->string('subjective_os_bcva', 20)->nullable();
            $table->text('subjective_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('refractions', function (Blueprint $table) {
            $table->dropColumn([
                'objective_method', 'objective_od_sphere', 'objective_od_cylinder',
                'objective_od_axis', 'objective_od_va', 'objective_os_sphere',
                'objective_os_cylinder', 'objective_os_axis', 'objective_os_va',
                'objective_notes', 'subjective_od_sphere', 'subjective_od_cylinder',
                'subjective_od_axis', 'subjective_od_add', 'subjective_od_bcva',
                'subjective_os_sphere', 'subjective_os_cylinder', 'subjective_os_axis',
                'subjective_os_add', 'subjective_os_bcva', 'subjective_notes',
            ]);
        });
    }
};
