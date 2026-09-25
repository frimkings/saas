<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('smtp_host', 255)->nullable()->after('report_recipients');
            $table->unsignedSmallInteger('smtp_port')->default(587)->after('smtp_host');
            $table->string('smtp_username', 255)->nullable()->after('smtp_port');
            $table->text('smtp_password')->nullable()->after('smtp_username');  // encrypted
            $table->string('smtp_encryption', 10)->nullable()->after('smtp_password'); // tls|ssl|null
            $table->string('smtp_from_address', 255)->nullable()->after('smtp_encryption');
            $table->string('smtp_from_name', 255)->nullable()->after('smtp_from_address');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
                'smtp_encryption', 'smtp_from_address', 'smtp_from_name',
            ]);
        });
    }
};
