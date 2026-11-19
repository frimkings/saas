<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-person access switches, per clinic: a permission turned on that the person's role lacks,
     * or turned off that it has. Access = role + switched on − switched off (plans still apply).
     */
    public function up(): void
    {
        if (Schema::hasTable('clinic_user_permissions')) return;

        Schema::create('clinic_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_id')->default(0)->index();
            $table->unsignedBigInteger('user_id');
            $table->string('permission', 64);
            $table->boolean('granted');
            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestamps();
            $table->unique(['clinic_id', 'user_id', 'permission'], 'clinic_user_permission_unique');
            $table->index(['user_id', 'clinic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_user_permissions');
    }
};
