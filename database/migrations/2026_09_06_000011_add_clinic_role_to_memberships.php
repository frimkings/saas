<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_user', function (Blueprint $table) {
            $table->string('clinic_role', 100)->nullable()->after('staff_identifier');
        });

        DB::table('clinic_user as cu')
            ->join('model_has_roles as mhr', function ($join) {
                $join->on('mhr.model_id', '=', 'cu.user_id')
                    ->where('mhr.model_type', '=', 'App\\Models\\User');
            })
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->whereNull('cu.clinic_role')
            ->update(['cu.clinic_role' => DB::raw('r.name')]);
    }

    public function down(): void
    {
        Schema::table('clinic_user', fn (Blueprint $table) => $table->dropColumn('clinic_role'));
    }
};
