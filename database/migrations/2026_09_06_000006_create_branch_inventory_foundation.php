<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $clinicId = DB::connection()->pretending()
            ? 1
            : (DB::table('clinics')->where('status', 'active')->orderBy('id')->value('id')
                ?? DB::table('clinics')->orderBy('id')->value('id'));
        $branchId = DB::connection()->pretending()
            ? 1
            : DB::table('branches')->where('clinic_id', $clinicId)->orderByDesc('is_default')->orderBy('id')->value('id');

        if (! $clinicId || ! $branchId) {
            throw new \RuntimeException('A clinic and default branch are required before inventory can be backfilled.');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->restrictOnDelete();
        });
        DB::table('products')->whereNull('clinic_id')->update(['clinic_id' => $clinicId]);

        foreach (['stocks', 'stock_movements', 'purchase_orders', 'purchase_order_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained('branches')->restrictOnDelete();
            });
            DB::table($tableName)->whereNull('clinic_id')->update(['clinic_id' => $clinicId, 'branch_id' => $branchId]);
            Schema::table($tableName, fn (Blueprint $table) => $table->index(['clinic_id', 'branch_id']));
        }

        Schema::create('branch_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reorder_level')->default(10);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
            $table->index(['clinic_id', 'branch_id', 'is_active']);
        });

        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('batch_number')->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->integer('opening_quantity')->default(0);
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->index(['branch_id', 'product_id', 'expiry_date']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('transfer_number', 50);
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['clinic_id', 'transfer_number']);
            $table->index(['clinic_id', 'branch_id', 'destination_branch_id', 'status'], 'stock_transfers_route_status_index');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->integer('quantity');
            $table->timestamps();
        });

        if (! DB::connection()->pretending()) {
            $now = now();
            DB::table('products')->orderBy('id')->chunkById(500, function ($products) use ($clinicId, $branchId, $now) {
                foreach ($products as $product) {
                    DB::table('branch_inventory_items')->insertOrIgnore([
                        'clinic_id' => $clinicId,
                        'branch_id' => $branchId,
                        'product_id' => $product->id,
                        'quantity' => (int) $product->quantity,
                        'reorder_level' => 10,
                        'is_active' => true,
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    if ((int) $product->quantity !== 0 || $product->batch_number || $product->expiry_date) {
                        DB::table('inventory_lots')->insert([
                            'uuid' => (string) \Illuminate\Support\Str::uuid(),
                            'clinic_id' => $clinicId,
                            'branch_id' => $branchId,
                            'product_id' => $product->id,
                            'batch_number' => $product->batch_number,
                            'manufacture_date' => $product->manufacture_date,
                            'expiry_date' => $product->expiry_date,
                            'unit_cost' => $product->cost_price,
                            'opening_quantity' => (int) $product->quantity,
                            'quantity' => (int) $product->quantity,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_name_unique');
            $table->unique(['clinic_id', 'name']);
            $table->dropUnique('products_batch_number_unique');
            $table->unique(['clinic_id', 'batch_number']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_reference_no_unique');
            $table->unique(['clinic_id', 'reference_no']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('purchase_orders_po_number_unique');
            $table->unique(['clinic_id', 'po_number']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'po_number']);
            $table->unique('po_number');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'reference_no']);
            $table->unique('reference_no');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['clinic_id', 'batch_number']);
            $table->unique('batch_number');
            $table->dropUnique(['clinic_id', 'name']);
            $table->unique('name');
        });

        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('inventory_lots');
        Schema::dropIfExists('branch_inventory_items');

        foreach (array_reverse(['stocks', 'stock_movements', 'purchase_orders', 'purchase_order_items']) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['clinic_id', 'branch_id']);
                $table->dropConstrainedForeignId('branch_id');
                $table->dropConstrainedForeignId('clinic_id');
            });
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('clinic_id'));
    }
};
