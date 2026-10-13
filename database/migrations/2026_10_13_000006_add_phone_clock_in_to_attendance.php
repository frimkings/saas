<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clocking in from a phone: a branch can let staff punch from their own profile page when
     * their phone is within so many metres of the branch. Where they were is kept on the punch.
     */
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->boolean('phone_clock_in')->default(false)->after('deduct_absence');
            // Days from here on are accounted for by phone punches, as a terminal accounts for its days.
            $table->date('phone_clock_in_since')->nullable()->after('phone_clock_in');
            $table->decimal('clock_in_latitude', 10, 7)->nullable()->after('phone_clock_in_since');
            $table->decimal('clock_in_longitude', 10, 7)->nullable()->after('clock_in_latitude');
            $table->unsignedSmallInteger('clock_in_radius')->default(150)->after('clock_in_longitude'); // metres
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('source');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('accuracy')->nullable()->after('longitude'); // metres, as the phone reported
            $table->unsignedInteger('distance')->nullable()->after('accuracy'); // metres from the branch
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude', 'accuracy', 'distance']));
        Schema::table('attendance_settings', fn (Blueprint $table) => $table->dropColumn(['phone_clock_in', 'phone_clock_in_since', 'clock_in_latitude', 'clock_in_longitude', 'clock_in_radius']));
    }
};
