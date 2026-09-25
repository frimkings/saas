<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('legacy_import_batches',function(Blueprint $t){$t->string('monitoring_status',30)->default('pending')->index()->after('cutover_status');$t->json('monitoring_report')->nullable()->after('cutover_checklist');$t->foreignId('monitoring_checked_by')->nullable()->after('cutover_approved_by')->constrained('users')->nullOnDelete();$t->timestamp('monitoring_checked_at')->nullable()->after('cutover_approved_at');$t->timestamp('migration_closed_at')->nullable()->after('monitoring_checked_at');});}
 public function down():void{Schema::table('legacy_import_batches',function(Blueprint $t){$t->dropForeign(['monitoring_checked_by']);$t->dropColumn(['monitoring_status','monitoring_report','monitoring_checked_by','monitoring_checked_at','migration_closed_at']);});}
};
