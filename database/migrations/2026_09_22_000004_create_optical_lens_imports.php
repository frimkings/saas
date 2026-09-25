<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('optical_lens_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('filename');
            $table->string('worksheet');
            $table->string('fingerprint', 64);
            $table->json('specifications');
            $table->string('source_unit', 10);
            $table->unsignedInteger('source_quantity');
            $table->unsignedInteger('pieces');
            $table->string('supplier', 180);
            $table->string('reference', 100)->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->text('repeat_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'fingerprint'], 'lens_import_duplicate_lookup');
        });
        Schema::table('optical_product_stock_movements', function (Blueprint $table) {
            $table->foreignId('optical_lens_import_id')->nullable()->constrained('optical_lens_imports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('optical_product_stock_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('optical_lens_import_id'));
        Schema::dropIfExists('optical_lens_imports');
    }
};
