<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::table('clinics',function(Blueprint $t){$t->string('billing_legal_name')->nullable()->after('billing_phone');$t->string('billing_tax_id',80)->nullable()->after('billing_legal_name');$t->text('billing_address')->nullable()->after('billing_tax_id');});
  Schema::table('platform_invoices',function(Blueprint $t){$t->decimal('credited_amount',12,2)->default(0)->after('amount_paid');$t->decimal('refunded_amount',12,2)->default(0)->after('credited_amount');$t->text('void_reason')->nullable()->after('voided_at');$t->foreignId('voided_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete();});
  Schema::create('platform_credit_notes',function(Blueprint $t){$t->id();$t->string('number')->unique();$t->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();$t->foreignId('platform_invoice_id')->constrained('platform_invoices')->restrictOnDelete();$t->decimal('amount',12,2);$t->string('currency',3);$t->text('reason');$t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('issued_at');$t->timestamps();});
  Schema::create('platform_payment_refunds',function(Blueprint $t){$t->id();$t->string('number')->unique();$t->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();$t->foreignId('platform_invoice_id')->constrained('platform_invoices')->restrictOnDelete();$t->foreignId('platform_payment_id')->constrained('platform_payments')->restrictOnDelete();$t->decimal('amount',12,2);$t->string('currency',3);$t->string('method',30);$t->string('reference')->nullable();$t->text('reason');$t->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('refunded_at');$t->timestamps();});
 }
 public function down():void{Schema::dropIfExists('platform_payment_refunds');Schema::dropIfExists('platform_credit_notes');Schema::table('platform_invoices',fn(Blueprint $t)=>$t->dropConstrainedForeignId('voided_by'));Schema::table('platform_invoices',fn(Blueprint $t)=>$t->dropColumn(['credited_amount','refunded_amount','void_reason']));Schema::table('clinics',fn(Blueprint $t)=>$t->dropColumn(['billing_legal_name','billing_tax_id','billing_address']));}
};
