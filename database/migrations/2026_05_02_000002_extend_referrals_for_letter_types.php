<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExtendReferralsForLetterTypes extends Migration
{
    public function up()
    {
        if (DB::getDriverName() === 'sqlite') {
        Schema::table('referrals', function (Blueprint $table) {
            $table->string('referral_to')->nullable()->change();
        });
    } else {
        DB::statement('ALTER TABLE referrals MODIFY referral_to VARCHAR(255) NULL');
    }

        Schema::table('referrals', function (Blueprint $table) {
        $table->enum('letter_type', ['referral', 'medical_report', 'excuse_duty'])
              ->default('referral')
              ->after('id');

        $table->text('clinical_findings')->nullable()->after('iop');
        $table->text('treatment')->nullable()->after('clinical_findings');
        $table->text('recommendation')->nullable()->after('treatment');

        $table->date('excuse_from_date')->nullable()->after('recommendation');
        $table->date('excuse_to_date')->nullable()->after('excuse_from_date');
        });
    }


    public function down()
    {
        Schema::table('referrals', function (Blueprint $table) {
        $table->dropColumn([
            'letter_type',
            'clinical_findings',
            'treatment',
            'recommendation',
            'excuse_from_date',
            'excuse_to_date',
        ]);
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('referrals', function (Blueprint $table) {
                $table->string('referral_to')->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE referrals MODIFY referral_to VARCHAR(255) NOT NULL');
        }
    }
}
