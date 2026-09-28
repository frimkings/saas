<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email log also records emails to the platform's requests inbox (App\Services\PlatformRequestAlerts).
 * Those about one clinic keep its id; the daily reminder covers several clinics and has none.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('owner_emails', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Platform-wide rows have no clinic; they are left in place and the column stays nullable.
    }
};
