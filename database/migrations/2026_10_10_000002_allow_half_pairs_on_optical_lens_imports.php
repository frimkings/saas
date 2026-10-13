<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A worksheet counted in pairs may end in a half pair (one loose lens), e.g. 64.5 pairs. */
    public function up(): void
    {
        Schema::table('optical_lens_imports', fn (Blueprint $table) => $table->decimal('source_quantity', 10, 1)->unsigned()->change());
    }

    public function down(): void
    {
        Schema::table('optical_lens_imports', fn (Blueprint $table) => $table->unsignedInteger('source_quantity')->change());
    }
};
