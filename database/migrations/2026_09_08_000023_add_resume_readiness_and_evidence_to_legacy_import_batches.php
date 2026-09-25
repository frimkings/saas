<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('legacy_import_batches',function(Blueprint $t){$t->uuid('resume_token')->nullable()->index()->after('monitoring_status');$t->json('resume_checkpoint')->nullable()->after('resume_token');$t->timestamp('checkpoint_at')->nullable()->after('resume_checkpoint');$t->json('readiness_report')->nullable()->after('monitoring_report');$t->timestamp('readiness_checked_at')->nullable()->after('monitoring_checked_at');$t->json('evidence_manifest')->nullable()->after('readiness_report');$t->string('evidence_path')->nullable()->after('evidence_manifest');$t->string('evidence_checksum',64)->nullable()->after('evidence_path');$t->timestamp('evidence_archived_at')->nullable()->after('migration_closed_at');});}
 public function down():void{Schema::table('legacy_import_batches',fn(Blueprint $t)=>$t->dropColumn(['resume_token','resume_checkpoint','checkpoint_at','readiness_report','readiness_checked_at','evidence_manifest','evidence_path','evidence_checksum','evidence_archived_at']));}
};
