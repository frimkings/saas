<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an attendance terminal sent, exactly as it arrived (AttendanceCaptureController). Used to
     * learn a device's format before punches are read from it; face photos are never kept.
     */
    public function up(): void
    {
        Schema::create('attendance_captures', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->boolean('secure');
            $table->string('ip', 45)->nullable();
            $table->string('content_type')->nullable();
            $table->json('headers');
            // Form fields PHP parsed out of a multipart body, and the files' names and sizes only.
            $table->json('fields')->nullable();
            $table->json('files')->nullable();
            $table->mediumText('body')->nullable();
            $table->timestamp('received_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_captures');
    }
};
