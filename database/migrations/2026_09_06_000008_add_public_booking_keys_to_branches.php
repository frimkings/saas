<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('public_booking_key', 64)->nullable()->unique()->after('receipt_prefix');
        });
        DB::table('branches')->whereNull('public_booking_key')->orderBy('id')->eachById(function ($branch) {
            DB::table('branches')->where('id', $branch->id)->update(['public_booking_key' => Str::random(48)]);
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('public_booking_key'));
    }
};
