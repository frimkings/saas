<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        if (!$isSqlite) {
            DB::statement("ALTER TABLE referrals MODIFY status ENUM('pending','completed','cancelled','draft','issued') NOT NULL DEFAULT 'draft'");
        }

        DB::table('referrals')->where('status', 'pending')->update(['status' => 'draft']);
        DB::table('referrals')->where('status', 'completed')->update(['status' => 'issued']);

        if (!$isSqlite) {
            DB::statement("ALTER TABLE referrals MODIFY status ENUM('draft','issued','cancelled') NOT NULL DEFAULT 'draft'");
        }

        Schema::table('referrals', function (Blueprint $table) {
            if (!Schema::hasColumn('referrals', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('referred_by')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('referrals', 'issued_by')) {
                $table->foreignId('issued_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('referrals', 'issued_at')) {
                $table->timestamp('issued_at')->nullable()->after('issued_by');
            }
            if (!Schema::hasColumn('referrals', 'printed_by')) {
                $table->foreignId('printed_by')->nullable()->after('issued_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('referrals', 'printed_at')) {
                $table->timestamp('printed_at')->nullable()->after('printed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            if (Schema::hasColumn('referrals', 'printed_by')) {
                $table->dropConstrainedForeignId('printed_by');
            }
            if (Schema::hasColumn('referrals', 'printed_at')) {
                $table->dropColumn('printed_at');
            }
            if (Schema::hasColumn('referrals', 'issued_by')) {
                $table->dropConstrainedForeignId('issued_by');
            }
            if (Schema::hasColumn('referrals', 'issued_at')) {
                $table->dropColumn('issued_at');
            }
            if (Schema::hasColumn('referrals', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }
        });

        $isSqlite = DB::getDriverName() === 'sqlite';

        if (!$isSqlite) {
            DB::statement("ALTER TABLE referrals MODIFY status ENUM('pending','completed','cancelled','draft','issued') NOT NULL DEFAULT 'pending'");
        }

        DB::table('referrals')->where('status', 'draft')->update(['status' => 'pending']);
        DB::table('referrals')->where('status', 'issued')->update(['status' => 'completed']);

        if (!$isSqlite) {
            DB::statement("ALTER TABLE referrals MODIFY status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
