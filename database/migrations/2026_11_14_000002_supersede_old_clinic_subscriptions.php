<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A clinic's newest subscription is its current one. Older rows used to keep their status
 * ("active", "trial"...) after a plan change, so billing kept issuing renewal invoices for them.
 * They are now marked "superseded", keeping the status they had for the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_subscriptions', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('suspended_at');
            $table->string('superseded_status', 20)->nullable()->after('superseded_at');
        });

        $latest = DB::table('clinic_subscriptions')->selectRaw('MAX(id) as id')->groupBy('clinic_id')->pluck('id');
        DB::table('clinic_subscriptions')
            ->whereNotIn('id', $latest)
            ->whereIn('status', ['trial', 'active', 'overdue', 'restricted'])
            ->update([
                'superseded_status' => DB::raw('status'),
                'status' => 'superseded',
                'superseded_at' => now(),
                'status_changed_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('clinic_subscriptions')->where('status', 'superseded')->whereNotNull('superseded_status')
            ->update(['status' => DB::raw('superseded_status')]);
        Schema::table('clinic_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['superseded_at', 'superseded_status']);
        });
    }
};
