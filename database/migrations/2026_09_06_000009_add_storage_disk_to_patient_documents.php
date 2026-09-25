<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_documents', fn (Blueprint $table) => $table->string('storage_disk', 30)->default('public')->after('file_path'));
    }

    public function down(): void
    {
        Schema::table('patient_documents', fn (Blueprint $table) => $table->dropColumn('storage_disk'));
    }
};
