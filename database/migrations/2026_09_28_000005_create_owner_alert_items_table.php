<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The morning alerts email (App\Services\OwnerAlertDigestService): what has already been
 * flagged to the owner, so each item is reported once per stage (low stock, 90/30 days to
 * expiry, bill due then overdue ...) and again only if it clears and comes back.
 *
 * Plans with the daily sales email also get the morning alerts; "every feature" plans get
 * it anyway.
 */
return new class extends Migration {
    private const FEATURE = 'owner_morning_alerts';

    public function up(): void
    {
        Schema::create('owner_alert_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('section', 30);
            $table->string('item_key', 80);
            $table->string('stage', 20);
            // What the email shows, kept so a failed send can be retried as it was.
            $table->string('title');
            $table->string('detail')->nullable();
            $table->string('branch_name')->nullable();
            $table->date('alerted_on');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['clinic_id', 'section', 'item_key', 'stage']);
            $table->index(['clinic_id', 'resolved_at', 'alerted_on']);
        });

        $this->convert(fn (array $f) => in_array('owner_daily_summary', $f, true) ? array_values(array_unique([...$f, self::FEATURE])) : $f);
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_alert_items');
        $this->convert(fn (array $f) => array_values(array_diff($f, [self::FEATURE])));
    }

    private function convert(callable $change): void
    {
        foreach (['subscription_plans' => 'features', 'clinic_subscriptions' => 'feature_snapshot'] as $table => $column) {
            if (! Schema::hasColumn($table, $column)) continue;
            DB::table($table)->whereNotNull($column)->orderBy('id')->each(function ($row) use ($table, $column, $change) {
                $features = json_decode((string) $row->{$column}, true);
                if (! is_array($features)) return;
                $new = $change($features);
                if ($new !== $features) DB::table($table)->where('id', $row->id)->update([$column => json_encode($new)]);
            });
        }
    }
};
