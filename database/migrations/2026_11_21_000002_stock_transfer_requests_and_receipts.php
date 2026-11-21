<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21 Nov 2026: a branch can ask another for stock (requested → sent or declined), a transfer not yet sent can be
 * cancelled, and the receiving branch counts what arrived (a shortfall is recorded, not hidden).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('response_note', 500)->nullable()->after('notes');
            $table->foreignId('answered_by')->nullable()->after('response_note')->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable()->after('answered_by');
        });
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->unsignedInteger('received_quantity')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn('received_quantity');
        });
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answered_by');
            $table->dropColumn(['response_note', 'answered_at']);
        });
    }
};
