<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('billing_notification_logs',function(Blueprint $t){$t->id();$t->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();$t->foreignId('clinic_subscription_id')->nullable()->constrained('clinic_subscriptions')->nullOnDelete();$t->foreignId('platform_invoice_id')->nullable()->constrained('platform_invoices')->nullOnDelete();$t->string('event',50);$t->string('deduplication_key')->unique();$t->json('recipients')->nullable();$t->string('status',20)->default('sent');$t->text('error')->nullable();$t->timestamp('sent_at')->nullable();$t->timestamps();$t->index(['clinic_id','event']);});}
 public function down():void{Schema::dropIfExists('billing_notification_logs');}
};
