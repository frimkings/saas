<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refractions', function (Blueprint $table) {
            $table->foreignId('dispensing_authorized_by')->nullable()->after('dispensing_required')->constrained('users')->restrictOnDelete();
            $table->timestamp('dispensing_authorized_at')->nullable()->after('dispensing_authorized_by');
        });
    }

    public function down(): void
    {
        Schema::table('refractions', function (Blueprint $table) {
            $table->dropForeign(['dispensing_authorized_by']);
            $table->dropColumn(['dispensing_authorized_by', 'dispensing_authorized_at']);
        });
    }
};
