<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which terminal a punch came from, for places with one at each entrance or ward. */
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('attendance_device_id')->nullable()->after('attendance_import_id')->constrained('attendance_devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', fn (Blueprint $table) => $table->dropConstrainedForeignId('attendance_device_id'));
    }
};
