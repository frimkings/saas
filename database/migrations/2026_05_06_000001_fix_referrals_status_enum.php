<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class FixReferralsStatusEnum extends Migration
{
    public function up()
    {
        // 💡 ONLY execute raw MySQL statements if we are NOT on an offline SQLite engine
        if (DB::getDriverName() !== 'sqlite' && config('database.default') !== 'nativephp') {
            
            // Temporarily widen the ENUM to allow both old and new values
            DB::statement("ALTER TABLE referrals MODIFY COLUMN status ENUM('draft','issued','pending','completed','cancelled') NOT NULL DEFAULT 'pending'");

            // Map old values to new ones
            DB::table('referrals')->where('status', 'draft')->update(['status' => 'pending']);
            DB::table('referrals')->where('status', 'issued')->update(['status' => 'completed']);

            // Lock to the final set of values
            DB::statement("ALTER TABLE referrals MODIFY COLUMN status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending'");
            
        } else {
            // 🖥️ SQLite/Desktop Mode Handler:
            // SQLite treats strings dynamically and does not strictly enforce hardcoded ENUM syntax constraints, 
            // so we can directly update the data rows safely without altering column types first.
            DB::table('referrals')->where('status', 'draft')->update(['status' => 'pending']);
            DB::table('referrals')->where('status', 'issued')->update(['status' => 'completed']);
        }
    }

    public function down()
    {
        if (DB::getDriverName() !== 'sqlite' && config('database.default') !== 'nativephp') {
            DB::statement("ALTER TABLE referrals MODIFY COLUMN status ENUM('draft','issued','cancelled') NOT NULL DEFAULT 'draft'");
        }
    }
}
