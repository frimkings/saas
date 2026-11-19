<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * After how many days a job that hasn't started (Pending) and still holds stock lenses is
     * flagged under Needs attention, so a manager releases the lenses or keeps them on purpose.
     */
    public function up(): void
    {
        if (Schema::hasColumn('optical_settings', 'lens_hold_prompt_days')) return;
        Schema::table('optical_settings', fn (Blueprint $table) => $table->unsignedSmallInteger('lens_hold_prompt_days')->default(1));
    }

    public function down(): void
    {
        if (Schema::hasColumn('optical_settings', 'lens_hold_prompt_days')) {
            Schema::table('optical_settings', fn (Blueprint $table) => $table->dropColumn('lens_hold_prompt_days'));
        }
    }
};
