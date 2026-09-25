<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('optical_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('optical_category_id')->constrained('optical_categories')->restrictOnDelete();
            $table->string('sku', 80);
            $table->string('name', 180);
            $table->string('brand', 120)->nullable();
            $table->text('specifications')->nullable();
            $table->decimal('cost_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['clinic_id', 'sku']);
            $table->index(['clinic_id', 'optical_category_id']);
        });

        Schema::create('optical_product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('optical_product_id')->constrained('optical_products')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reorder_level')->default(5);
            $table->timestamps();
            $table->unique(['branch_id', 'optical_product_id']);
        });

        Schema::create('optical_product_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('optical_product_id')->constrained('optical_products')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('quantity_change');
            $table->unsignedInteger('balance_after');
            $table->string('reason', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_product_stock_movements');
        Schema::dropIfExists('optical_product_stocks');
        Schema::dropIfExists('optical_products');
    }
};
