<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('clinic_offboarding_requests',function(Blueprint $t){$t->id();$t->uuid('uuid')->unique();$t->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();$t->foreignId('clinic_subscription_id')->constrained('clinic_subscriptions')->restrictOnDelete();$t->string('timing',20);$t->string('status',30);$t->text('reason');$t->timestamp('effective_at');$t->timestamp('retention_until')->nullable();$t->string('export_path')->nullable();$t->string('export_checksum',64)->nullable();$t->json('checklist')->nullable();$t->json('blockers')->nullable();$t->foreignId('requested_by')->constrained('users')->restrictOnDelete();$t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();$t->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();$t->text('review_notes')->nullable();$t->timestamp('approved_at')->nullable();$t->timestamp('reversed_at')->nullable();$t->timestamp('completed_at')->nullable();$t->timestamps();$t->index(['status','effective_at']);});}
 public function down():void{Schema::dropIfExists('clinic_offboarding_requests');}
};
