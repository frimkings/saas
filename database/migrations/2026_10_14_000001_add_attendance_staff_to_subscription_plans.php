<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many staff a plan may follow on attendance; null = unlimited. A small number makes it a
     * small clinic's package; above App\Support\AttendancePlan::smallClinicMax() it brings the
     * hospital tools.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('attendance_staff')->nullable()->after('included_users');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropColumn('attendance_staff'));
    }
};
