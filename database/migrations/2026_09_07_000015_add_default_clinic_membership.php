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
            $table->boolean('is_default')->default(false)->after('status');
            $table->index(['user_id', 'is_default']);
        });

        DB::table('clinic_user')->select('user_id')->distinct()->orderBy('user_id')->chunk(500, function ($users) {
            foreach ($users as $user) {
                $clinicId = DB::table('clinic_user')->join('clinics', 'clinics.id', '=', 'clinic_user.clinic_id')
                    ->where('clinic_user.user_id', $user->user_id)
                    ->where('clinic_user.status', 'active')->where('clinics.status', 'active')
                    ->orderBy('clinic_user.clinic_id')->value('clinic_user.clinic_id');
                if ($clinicId) {
                    DB::table('clinic_user')->where('user_id', $user->user_id)->where('clinic_id', $clinicId)->update(['is_default' => true]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinic_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_default']);
            $table->dropColumn('is_default');
        });
    }
};
