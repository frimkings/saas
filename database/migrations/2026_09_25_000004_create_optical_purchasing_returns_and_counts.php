<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Supplier orders for optical stock and special-order lenses (separate from clinic POs).
        Schema::create('optical_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('po_number', 40)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft, ordered, partially_received, received, cancelled
            $table->date('expected_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('optical_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('optical_purchase_order_id')->constrained('optical_purchase_orders')->cascadeOnDelete();
            // Stock line: a product to restock. Special-order line: a lens for a customer job.
            $table->foreignId('optical_product_id')->nullable()->constrained('optical_products')->restrictOnDelete();
            $table->foreignId('lens_order_id')->nullable()->constrained('lens_orders')->nullOnDelete();
            $table->string('eye', 2)->nullable();
            $table->string('description');
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('optical_supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('return_number', 40)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('optical_purchase_order_id')->nullable()->constrained('optical_purchase_orders')->nullOnDelete();
            $table->string('reason', 30);
            $table->text('notes')->nullable();
            $table->decimal('credit_expected', 12, 2)->default(0);
            $table->string('credit_status', 20)->default('pending'); // pending, credited, replaced, written_off
            $table->string('credit_reference', 100)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('optical_supplier_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('optical_supplier_return_id');
            $table->foreign('optical_supplier_return_id', 'optical_return_lines_return_fk')->references('id')->on('optical_supplier_returns')->cascadeOnDelete();
            $table->foreignId('optical_product_id')->constrained('optical_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('optical_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('count_number', 40)->unique();
            $table->string('scope', 20); // lens_range, frames, other, all
            $table->json('scope_specs')->nullable();
            $table->string('title');
            $table->string('status', 20)->default('counting'); // counting, submitted, approved, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('optical_stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('optical_stock_count_id')->constrained('optical_stock_counts')->cascadeOnDelete();
            $table->foreignId('optical_product_id')->constrained('optical_products')->restrictOnDelete();
            $table->integer('expected_quantity');
            $table->unsignedInteger('counted_quantity')->nullable();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['optical_stock_count_id', 'optical_product_id'], 'optical_count_line_product_unique');
        });

        // Trace every stock movement back to the document that caused it.
        Schema::table('optical_product_stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('optical_purchase_order_line_id')->nullable();
            $table->unsignedBigInteger('optical_supplier_return_id')->nullable();
            $table->unsignedBigInteger('optical_stock_count_id')->nullable();
            $table->foreign('optical_purchase_order_line_id', 'optical_movements_po_line_fk')->references('id')->on('optical_purchase_order_lines')->nullOnDelete();
            $table->foreign('optical_supplier_return_id', 'optical_movements_return_fk')->references('id')->on('optical_supplier_returns')->nullOnDelete();
            $table->foreign('optical_stock_count_id', 'optical_movements_count_fk')->references('id')->on('optical_stock_counts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('optical_product_stock_movements', function (Blueprint $table) {
            $table->dropForeign('optical_movements_count_fk');
            $table->dropForeign('optical_movements_return_fk');
            $table->dropForeign('optical_movements_po_line_fk');
            $table->dropColumn(['optical_stock_count_id', 'optical_supplier_return_id', 'optical_purchase_order_line_id']);
        });
        Schema::dropIfExists('optical_stock_count_lines');
        Schema::dropIfExists('optical_stock_counts');
        Schema::dropIfExists('optical_supplier_return_lines');
        Schema::dropIfExists('optical_supplier_returns');
        Schema::dropIfExists('optical_purchase_order_lines');
        Schema::dropIfExists('optical_purchase_orders');
    }
};
