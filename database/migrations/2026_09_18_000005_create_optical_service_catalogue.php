<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('optical_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('requires_rx')->default(false);
            $table->boolean('requires_frame')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['clinic_id', 'code']);
        });

        Schema::table('optical_order_services', function (Blueprint $table) {
            $table->foreignId('optical_service_id')->nullable()->after('lens_order_id')->constrained('optical_services')->nullOnDelete();
        });

        // Keep quotations created before the catalogue was introduced editable.
        foreach (DB::table('optical_order_services')->orderBy('id')->cursor() as $line) {
            $service = DB::table('optical_services')
                ->where('clinic_id', $line->clinic_id)->where('code', $line->service_code)->first();
            $serviceId = $service?->id ?? DB::table('optical_services')->insertGetId([
                'clinic_id' => $line->clinic_id, 'code' => $line->service_code,
                'name' => $line->description, 'price' => $line->unit_price,
                'requires_rx' => $line->requires_rx, 'requires_frame' => $line->requires_frame,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('optical_order_services')->where('id', $line->id)
                ->update(['optical_service_id' => $serviceId]);
        }
    }

    public function down(): void
    {
        Schema::table('optical_order_services', fn (Blueprint $table) => $table->dropConstrainedForeignId('optical_service_id'));
        Schema::dropIfExists('optical_services');
    }
};
