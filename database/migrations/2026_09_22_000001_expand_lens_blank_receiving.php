<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('optical_lens_blanks', 'design')) Schema::table('optical_lens_blanks', function (Blueprint $table) {
            $table->string('design', 30)->default('Single Vision');
            $table->string('product_range', 100)->default('');
            $table->unsignedSmallInteger('diameter')->default(0);
            $table->decimal('addition', 5, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->nullable();
        });
        if (! Schema::hasIndex('optical_lens_blanks', 'lens_blank_variant_unique')) Schema::table('optical_lens_blanks', function (Blueprint $table) {
            $table->unique(['clinic_id', 'branch_id', 'design', 'product_range', 'diameter', 'lens_index', 'coating', 'sphere', 'cylinder', 'addition'], 'lens_blank_variant_unique');
        });
        if (Schema::hasIndex('optical_lens_blanks', 'lens_blanks_location_power_unique')) Schema::table('optical_lens_blanks', function (Blueprint $table) {
            $table->dropUnique('lens_blanks_location_power_unique');
        });
        if (! Schema::hasColumn('optical_lens_blank_movements', 'unit_cost')) Schema::table('optical_lens_blank_movements', function (Blueprint $table) {
            $table->string('supplier', 180)->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        // Variant stock cannot safely be collapsed into the old unique key.
        throw new LogicException('Reconcile lens variants before reverting this migration.');
    }
};
