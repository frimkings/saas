<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('legacy_import_batches', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('status');
            $table->string('current_table')->nullable()->after('progress_percent');
            $table->unsignedBigInteger('processed_rows')->default(0)->after('current_table');
            $table->unsignedBigInteger('total_rows')->default(0)->after('processed_rows');
            $table->unsignedSmallInteger('attempts')->default(0)->after('total_rows');
            $table->timestamp('started_at')->nullable()->after('analyzed_at');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_import_batches', fn (Blueprint $table) => $table->dropColumn([
            'progress_percent','current_table','processed_rows','total_rows','attempts','started_at','finished_at',
        ]));
    }
};
