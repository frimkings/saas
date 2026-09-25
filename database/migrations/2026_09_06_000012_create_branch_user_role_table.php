<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user_role', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['branch_id', 'user_id', 'role_id']);
            $table->index(['user_id', 'branch_id']);
        });

        $now = now();
        DB::table('branch_user as bu')
            ->join('model_has_roles as mhr', function ($join) {
                $join->on('mhr.model_id', '=', 'bu.user_id')
                    ->where('mhr.model_type', '=', 'App\\Models\\User');
            })
            ->select('bu.branch_id', 'bu.user_id', 'mhr.role_id')
            ->orderBy('bu.branch_id')->chunk(500, function ($rows) use ($now) {
                DB::table('branch_user_role')->insertOrIgnore($rows->map(fn ($row) => [
                    'branch_id' => $row->branch_id, 'user_id' => $row->user_id,
                    'role_id' => $row->role_id, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user_role');
    }
};
