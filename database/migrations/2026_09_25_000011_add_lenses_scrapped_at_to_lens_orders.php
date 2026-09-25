<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a job is cancelled after glazing started, its lenses were cut for that customer and
 * cannot go back on the shelf. This records when they were written off, so the optical P&L
 * shows their cost as a loss.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lens_orders', fn (Blueprint $table) => $table->timestamp('lenses_scrapped_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('lens_orders', fn (Blueprint $table) => $table->dropColumn('lenses_scrapped_at'));
    }
};
