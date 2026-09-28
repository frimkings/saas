<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each clinic ticks which messages its patients get by SMS. Everything stays on until switched off. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('placeholders');
        });
    }

    public function down(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropColumn('is_enabled');
        });
    }
};
