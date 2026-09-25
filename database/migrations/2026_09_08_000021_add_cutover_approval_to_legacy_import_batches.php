<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('legacy_import_batches', function (Blueprint $table) {
            $table->string('cutover_status', 30)->default('pending')->index()->after('status');
            $table->json('cutover_checklist')->nullable()->after('result');
            $table->foreignId('cutover_approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('cutover_approved_at')->nullable()->after('committed_at');
            $table->text('cutover_notes')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_import_batches', function (Blueprint $table) {
            $table->dropForeign(['cutover_approved_by']);
            $table->dropColumn(['cutover_status','cutover_checklist','cutover_approved_by','cutover_approved_at','cutover_notes']);
        });
    }
};
