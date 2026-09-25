<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_logs', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'processed'])
                  ->default('pending')
                  ->after('sale_id');

            $table->unsignedBigInteger('rejected_by')->nullable()->after('processed_by');
            $table->timestamp('rejected_at')->nullable()->after('processed_at');
            $table->text('rejection_reason')->nullable()->after('reason');

            $table->foreign('rejected_by')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });

        // Existing rows were fully processed in the old single-step flow.
        DB::table('refund_logs')->whereNotNull('processed_at')->update(['status' => 'processed']);
    }

    public function down(): void
    {
        Schema::table('refund_logs', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['status', 'rejected_by', 'rejected_at', 'rejection_reason']);
        });
    }
};
