<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('license_key')->nullable()->after('va_notation');
            $table->string('installation_id', 36)->nullable()->after('license_key');
            $table->date('license_last_seen')->nullable()->after('installation_id');
            $table->date('trial_started_at')->nullable()->after('license_last_seen');
        });

        // Auto-generate installation_id and start trial for existing settings rows
        \App\Models\Setting::query()->whereNull('installation_id')->each(function ($s) {
            $s->installation_id  = (string) Str::uuid();
            $s->trial_started_at = now()->toDateString();
            $s->save();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['license_key', 'installation_id', 'license_last_seen', 'trial_started_at']);
        });
    }
};
