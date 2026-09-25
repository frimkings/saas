<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id');     
            $table->unsignedBigInteger('product_id');  
             $table->integer('prescribed_quantity')->default(0);
            $table->integer('dispensed_quantity');               
            $table->decimal('selling_price', 12, 2);   
            $table->decimal('subtotal', 12, 2);       
             $table->text('notes')->nullable();
       $table->string('frequency', 20)->nullable(); 

            $table->softDeletes();                      
            $table->timestamps();
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sale_items');
    }
};
