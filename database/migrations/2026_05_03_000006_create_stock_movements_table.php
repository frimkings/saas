<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_no')->unique();
            $table->string('movement_type')->default('received');
            $table->string('supplier')->nullable();
            $table->string('batch_number')->nullable();
            $table->integer('quantity_before')->default(0);
            $table->integer('quantity')->default(0);
            $table->integer('quantity_after')->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['movement_type', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_movements');
    }
};
