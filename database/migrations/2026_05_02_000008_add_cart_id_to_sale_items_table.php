<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('cart_id')
                ->nullable()
                ->after('sale_id')
                ->constrained('carts')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cart_id');
        });
    }
};
