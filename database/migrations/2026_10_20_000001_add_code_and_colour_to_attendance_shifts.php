<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shift's own short code (MPH, FS) and colour ('#0d9488') for the roster and everywhere a
     * shift is shown. Empty = worked out from the name and the shift's place in the day, as before.
     */
    public function up(): void
    {
        Schema::table('attendance_shifts', function (Blueprint $table) {
            $table->string('code', 4)->nullable()->after('name');
            $table->string('colour', 7)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_shifts', function (Blueprint $table) {
            $table->dropColumn(['code', 'colour']);
        });
    }
};
