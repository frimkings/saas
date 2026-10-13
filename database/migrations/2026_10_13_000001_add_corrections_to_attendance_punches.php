<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Managers can correct punches: add one that was missed, change a time, or remove one made by
     * mistake. Nothing is deleted: a removed punch is kept, marked voided, with who and why, and a
     * changed punch points at the one it replaced.
     */
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            // terminal (live push), report (uploaded file) or manual (entered by a manager).
            $table->string('source', 10)->default('terminal')->after('punched_at');
            $table->foreignId('created_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable()->after('created_by');
            $table->unsignedBigInteger('replaces_id')->nullable()->after('reason');
            $table->dateTime('voided_at')->nullable()->after('replaces_id');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable()->after('voided_by');
        });

        DB::table('attendance_punches')->whereNotNull('attendance_import_id')->update(['source' => 'report']);
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['source', 'reason', 'replaces_id', 'voided_at', 'void_reason']);
        });
    }
};
