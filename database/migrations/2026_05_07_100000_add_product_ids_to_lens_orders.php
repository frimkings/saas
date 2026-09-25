<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Change notes from varchar(255) to text to support long order trails
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('lens_orders', function (Blueprint $table) {
                $table->text('notes')->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE lens_orders MODIFY COLUMN notes TEXT NULL');
        }

        Schema::table('lens_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('frame_product_id')->nullable()->after('frame_model_number');
            $table->unsignedBigInteger('lens_product_id')->nullable()->after('frame_product_id');

            $table->foreign('frame_product_id')
                ->references('id')->on('products')
                ->onDelete('set null');

            $table->foreign('lens_product_id')
                ->references('id')->on('products')
                ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropForeign(['frame_product_id']);
            $table->dropForeign(['lens_product_id']);
            $table->dropColumn(['frame_product_id', 'lens_product_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('lens_orders', function (Blueprint $table) {
                $table->string('notes')->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE lens_orders MODIFY COLUMN notes VARCHAR(255) NULL');
        }
    }
};
