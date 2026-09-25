<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('bill_version')->default(1)->after('bill_status');
            $table->timestamp('expires_at')->nullable()->after('bill_version');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            $table->index(['bill_status', 'expires_at'], 'sales_open_bill_expiry_index');
        });

        Schema::create('sale_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 12, 2);
            $table->string('method', 30)->nullable();
            $table->decimal('input_value', 12, 2)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->index(['sale_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_adjustments');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_open_bill_expiry_index');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['bill_version', 'expires_at']);
        });
    }
};
