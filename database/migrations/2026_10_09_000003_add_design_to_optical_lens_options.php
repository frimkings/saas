<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A lens form (flat top, invisible, wider corridor) belongs to one lens type; null = every type. */
    public function up(): void
    {
        Schema::table('optical_lens_options', function (Blueprint $table) {
            $table->string('design', 30)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('optical_lens_options', fn (Blueprint $table) => $table->dropColumn('design'));
    }
};
