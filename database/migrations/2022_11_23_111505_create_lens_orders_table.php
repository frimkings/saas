<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLensOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lens_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('refraction_id')->unique();
            $table->string('order_id')->unique();
            $table->string('frame_model_number');
            $table->decimal('frame_price', 22, 2);
            $table->decimal('lens_price', 22, 2);
            $table->string('status')->default('Pending');
            $table->string('notes')->nullable();
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->date('pickUpDate');
            $table->foreign('refraction_id')->references('id')->on('refractions')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lens_orders');
    }
}
