<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // Products: expiry_date is used in WHERE on every POS render; category_id for filter
        Schema::table('products', function (Blueprint $table) {
            $table->index('expiry_date', 'products_expiry_date_index');
            $table->index('category_id', 'products_category_id_index');
        });

        // Carts: add purchased + dispensed_by to cover the common lookup patterns
        Schema::table('carts', function (Blueprint $table) {
            $table->index('dispensed_by', 'carts_dispensed_by_index');
            $table->index('product_id', 'carts_product_id_index');
            // Composite covering the most frequent pending-cart query
            $table->index(['patient_id', 'purchased', 'status'], 'carts_patient_purchased_status_index');
        });

        // Sales: reporting queries filter/sort by user_id and patient_id with created_at
        Schema::table('sales', function (Blueprint $table) {
            $table->index('patient_id', 'sales_patient_id_index');
            $table->index('user_id', 'sales_user_id_index');
            $table->index(['user_id', 'created_at'], 'sales_user_created_at_index');
            $table->index(['patient_id', 'created_at'], 'sales_patient_created_at_index');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_expiry_date_index');
            $table->dropIndex('products_category_id_index');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex('carts_dispensed_by_index');
            $table->dropIndex('carts_product_id_index');
            $table->dropIndex('carts_patient_purchased_status_index');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_patient_id_index');
            $table->dropIndex('sales_user_id_index');
            $table->dropIndex('sales_user_created_at_index');
            $table->dropIndex('sales_patient_created_at_index');
        });
    }
};
