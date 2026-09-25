<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->string('frame_model_number')->nullable()->change();
            $table->string('work_type', 30)->default('prescription');
            $table->string('partner_clinic_name')->nullable();
            $table->string('bill_to', 20)->default('customer');
            $table->decimal('service_total', 12, 2)->default(0);
        });

        Schema::create('optical_order_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('lens_order_id')->constrained('lens_orders')->cascadeOnDelete();
            $table->string('service_code', 60);
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->boolean('requires_rx')->default(false);
            $table->boolean('requires_frame')->default(false);
            $table->timestamps();
            $table->index(['clinic_id', 'lens_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optical_order_services');
        Schema::table('lens_orders', function (Blueprint $table) {
            $table->dropColumn(['work_type', 'partner_clinic_name', 'bill_to', 'service_total']);
        });
    }
};
