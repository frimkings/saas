<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Scheduled report delivery" becomes three features the platform ticks per plan: the daily,
 * weekly and monthly sales emails to the clinic owner. Plans and subscriptions that had report
 * delivery get all three, so no clinic loses its report. The old key is left in place.
 */
return new class extends Migration {
    private const OLD = 'report_delivery';
    private const NEW = ['owner_daily_summary', 'owner_weekly_summary', 'owner_monthly_summary'];

    public function up(): void
    {
        $this->convert('subscription_plans', 'features', fn (array $f) => array_values(array_unique([...$f, ...self::NEW])));
        $this->convert('clinic_subscriptions', 'feature_snapshot', fn (array $f) => array_values(array_unique([...$f, ...self::NEW])));
    }

    public function down(): void
    {
        $this->convert('subscription_plans', 'features', fn (array $f) => array_values(array_diff($f, self::NEW)));
        $this->convert('clinic_subscriptions', 'feature_snapshot', fn (array $f) => array_values(array_diff($f, self::NEW)));
    }

    private function convert(string $table, string $column, callable $change): void
    {
        if (! Schema::hasColumn($table, $column)) return;
        DB::table($table)->where($column, 'like', '%"' . self::OLD . '"%')->orderBy('id')->each(function ($row) use ($table, $column, $change) {
            $features = json_decode((string) $row->{$column}, true);
            if (! is_array($features) || ! in_array(self::OLD, $features, true)) return;
            DB::table($table)->where('id', $row->id)->update([$column => json_encode($change($features))]);
        });
    }
};
