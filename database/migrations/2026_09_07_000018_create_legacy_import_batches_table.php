<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::create('legacy_import_batches', function (Blueprint $t) { $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete(); $t->foreignId('created_by')->constrained('users'); $t->string('original_filename'); $t->string('stored_path'); $t->string('checksum',64); $t->string('status',30)->default('uploaded')->index(); $t->string('clinic_name')->nullable(); $t->string('clinic_slug')->nullable(); $t->string('admin_email')->nullable(); $t->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete(); $t->json('analysis')->nullable(); $t->json('conflicts')->nullable(); $t->json('result')->nullable(); $t->text('error')->nullable(); $t->timestamp('analyzed_at')->nullable(); $t->timestamp('committed_at')->nullable(); $t->timestamp('rolled_back_at')->nullable(); $t->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('legacy_import_batches'); }
};
