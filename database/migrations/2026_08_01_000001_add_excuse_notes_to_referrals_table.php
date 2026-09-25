<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExcuseNotesToReferralsTable extends Migration
{
    public function up()
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->text('excuse_notes')->nullable()->after('excuse_to_date');
        });
    }

    public function down()
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropColumn('excuse_notes');
        });
    }
}
