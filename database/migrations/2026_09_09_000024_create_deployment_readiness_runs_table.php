<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('deployment_readiness_runs',function(Blueprint $t){$t->id();$t->uuid('uuid')->unique();$t->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();$t->string('environment',30);$t->string('status',20)->index();$t->boolean('ready')->default(false)->index();$t->unsignedInteger('passed_checks')->default(0);$t->unsignedInteger('warning_checks')->default(0);$t->unsignedInteger('failed_checks')->default(0);$t->json('report');$t->string('host')->nullable();$t->timestamp('completed_at');$t->timestamps();});}
 public function down():void{Schema::dropIfExists('deployment_readiness_runs');}
};
