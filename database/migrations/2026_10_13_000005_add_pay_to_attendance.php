<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the payroll file needs: how each person is paid (a monthly salary or by the hour),
     * and the branch's rules for overtime pay and for taking lateness and absences off pay.
     */
    public function up(): void
    {
        Schema::table('attendance_staff', function (Blueprint $table) {
            $table->string('pay_type', 10)->nullable()->after('shift_week'); // monthly | hourly
            $table->decimal('pay_rate', 12, 2)->nullable()->after('pay_type'); // a month's salary, or an hour's pay
        });

        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->decimal('overtime_multiplier', 4, 2)->default(1.5)->after('overtime_needs_approval');
            $table->boolean('deduct_lateness')->default(false)->after('overtime_multiplier');
            $table->boolean('deduct_absence')->default(true)->after('deduct_lateness');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', fn (Blueprint $table) => $table->dropColumn(['overtime_multiplier', 'deduct_lateness', 'deduct_absence']));
        Schema::table('attendance_staff', fn (Blueprint $table) => $table->dropColumn(['pay_type', 'pay_rate']));
    }
};
