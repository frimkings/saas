<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave that staff ask for themselves and a manager approves or turns down, and leave for
     * part of a day: a morning, an afternoon or a few hours. Leave already entered stays approved.
     */
    public function up(): void
    {
        Schema::table('attendance_leaves', function (Blueprint $table) {
            // 'HH:MM' both, on a single day; null = whole days.
            $table->string('from_time', 5)->nullable()->after('ends_on');
            $table->string('to_time', 5)->nullable()->after('from_time');
            $table->string('status', 20)->default('approved')->after('note');
            $table->foreignId('requested_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note')->nullable()->after('reviewed_at');
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_leaves', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status']);
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['from_time', 'to_time', 'status', 'reviewed_at', 'review_note']);
        });
    }
};
