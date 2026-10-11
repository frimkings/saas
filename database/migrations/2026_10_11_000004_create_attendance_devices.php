<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An attendance terminal that sends each punch here as it happens (AttendancePushController).
     * The terminal is told an address ending in the token, which is all that identifies it.
     */
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token', 64)->unique();
            $table->boolean('is_active')->default(true);
            // When anything last arrived from it, by the server's clock: whether it is connected.
            $table->timestamp('last_seen_at')->nullable();
            // The first and latest event by the terminal's own clock: the days it accounts for.
            $table->dateTime('first_event_at')->nullable();
            $table->dateTime('last_event_at')->nullable();
            $table->unsignedBigInteger('punches_received')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_devices');
    }
};
