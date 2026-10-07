<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who moved an order to each status and when, so staff changes can be reviewed. */
    public function up(): void
    {
        Schema::create('lens_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('lens_order_id')->constrained('lens_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['lens_order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lens_order_events');
    }
};
