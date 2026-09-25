<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 25)->nullable()->after('email');
            $table->string('staff_id', 30)->nullable()->unique()->after('phone');
            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable()->after('staff_id');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('department', 100)->nullable()->after('date_of_birth');
            $table->date('hire_date')->nullable()->after('department');
            $table->string('avatar')->nullable()->after('hire_date');
            $table->timestamp('last_password_changed_at')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'staff_id', 'gender', 'date_of_birth',
                'department', 'hire_date', 'avatar', 'last_password_changed_at',
            ]);
        });
    }
};
