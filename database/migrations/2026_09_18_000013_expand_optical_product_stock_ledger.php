<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('optical_product_stock_movements', function (Blueprint $table) {
            $table->string('movement_type', 20)->default('system');
            $table->string('reference', 100)->nullable();
            $table->string('supplier', 180)->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reverses_movement_id')->nullable()->unique()
                ->constrained('optical_product_stock_movements')->restrictOnDelete();
            $table->index(['clinic_id', 'branch_id', 'created_at'], 'optical_stock_ledger_branch_date');
        });
    }

    public function down(): void
    {
        Schema::table('optical_product_stock_movements', function (Blueprint $table) {
            $table->dropForeign(['reverses_movement_id']);
            $table->dropUnique(['reverses_movement_id']);
            $table->dropIndex('optical_stock_ledger_branch_date');
            $table->dropColumn(['movement_type', 'reference', 'supplier', 'batch_number', 'unit_cost', 'unit_price', 'notes', 'reverses_movement_id']);
        });
    }
};
