<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('subscription_approval_requests',function(Blueprint $t){$t->id();$t->uuid('uuid')->unique();$t->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();$t->string('action',50);$t->string('target_type',80);$t->unsignedBigInteger('target_id');$t->json('payload');$t->string('payload_hash',64);$t->text('reason');$t->string('status',20)->default('pending');$t->foreignId('requested_by')->constrained('users')->restrictOnDelete();$t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();$t->text('review_notes')->nullable();$t->timestamp('expires_at');$t->timestamp('reviewed_at')->nullable();$t->timestamp('executed_at')->nullable();$t->timestamps();$t->index(['status','expires_at']);$t->index(['target_type','target_id']);});}
 public function down():void{Schema::dropIfExists('subscription_approval_requests');}
};
