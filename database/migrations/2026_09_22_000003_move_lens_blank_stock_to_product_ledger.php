<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            foreach (DB::table('optical_lens_blanks')->whereNull('optical_product_id')->get() as $blank) {
                $specs = ['range' => $blank->product_range, 'design' => $blank->design,
                    'index' => $blank->lens_index, 'coating' => $blank->coating, 'diameter' => (int) $blank->diameter,
                    'sphere' => number_format($blank->sphere, 2, '.', ''),
                    'power' => number_format($blank->design === 'Single Vision' ? $blank->cylinder : $blank->addition, 2, '.', '')];
                ksort($specs);
                $key = hash('sha256', json_encode($specs));
                $category = DB::table('optical_categories')->where('clinic_id', $blank->clinic_id)->where('code', 'stock-'.strtolower(str_replace(' ', '-', $blank->design)))->first();
                $categoryId = $category?->id ?? DB::table('optical_categories')->insertGetId([
                    'clinic_id' => $blank->clinic_id, 'code' => 'stock-'.strtolower(str_replace(' ', '-', $blank->design)),
                    'name' => $blank->design.' Stock Lenses', 'is_active' => true, 'default_markup' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $product = DB::table('optical_products')->where('clinic_id', $blank->clinic_id)->where('lens_key', $key)->first();
                $productId = $product?->id ?? DB::table('optical_products')->insertGetId([
                    'clinic_id' => $blank->clinic_id, 'optical_category_id' => $categoryId,
                    'sku' => 'LENS-'.substr($key, 0, 24), 'name' => substr(($blank->product_range ?: 'Legacy range').' '.$blank->design.' '.$blank->coating.' '.$specs['sphere'].' / '.$specs['power'], 0, 180),
                    'lens_key' => $key, 'lens_specs' => json_encode($specs), 'cost_price' => 0,
                    'selling_price' => $blank->selling_price ?? 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('optical_product_stocks')->insert([
                    'clinic_id' => $blank->clinic_id, 'branch_id' => $blank->branch_id, 'optical_product_id' => $productId,
                    'quantity' => $blank->quantity, 'reorder_level' => $blank->reorder_level, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (DB::table('optical_lens_blank_movements')->where('optical_lens_blank_id', $blank->id)->orderBy('id')->get() as $movement) {
                    DB::table('optical_product_stock_movements')->insert([
                        'clinic_id' => $blank->clinic_id, 'branch_id' => $blank->branch_id, 'optical_product_id' => $productId,
                        'user_id' => $movement->user_id, 'quantity_change' => $movement->quantity_change,
                        'balance_after' => $movement->balance_after, 'reason' => 'Legacy lens movement #'.$movement->id,
                        'movement_type' => 'system', 'reference' => $movement->reference, 'supplier' => $movement->supplier,
                        'unit_cost' => $movement->unit_cost, 'unit_price' => $movement->unit_price,
                        'created_at' => $movement->created_at, 'updated_at' => $movement->updated_at,
                    ]);
                }
                DB::table('optical_lens_blanks')->where('id', $blank->id)->update(['optical_product_id' => $productId]);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Unified lens inventory must be reconciled before rollback.');
    }
};
