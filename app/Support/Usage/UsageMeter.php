<?php

namespace App\Support\Usage;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-clinic usage counters (Platform → Usage). The middleware measures each clinic request
 * and adds it to that clinic's row for the day with a single upsert, written after the
 * response has gone out. The database time comes from a query listener (AppServiceProvider).
 */
class UsageMeter
{
    private float $dbMs = 0;
    private int $dbQueries = 0;

    public static function enabled(): bool
    {
        $setting = config('tenancy.usage_metering');

        return $setting === null || $setting === '' ? (bool) config('tenancy.enabled') : filter_var($setting, FILTER_VALIDATE_BOOL);
    }

    public function addQuery(float $ms): void
    {
        $this->dbMs += $ms;
        $this->dbQueries++;
    }

    /** Database time and query count so far in this request. */
    public function database(): array
    {
        return [$this->dbMs, $this->dbQueries];
    }

    public function reset(): void
    {
        $this->dbMs = 0;
        $this->dbQueries = 0;
    }

    /** Adds one request to the clinic's row for today. Never breaks the request it measures. */
    public static function record(int $clinicId, array $usage, ?string $date = null): void
    {
        $row = ['requests' => 1, 'page_views' => 0, 'actions' => 0, 'bytes_out' => 0, 'bytes_in' => 0,
            'server_ms' => 0, 'db_ms' => 0, 'db_queries' => 0];
        foreach ($usage as $key => $value) {
            if (array_key_exists($key, $row)) {
                $row[$key] = max(0, (int) round($value));
            }
        }
        $now = now();

        try {
            DB::table('clinic_usage_daily')->upsert(
                [['clinic_id' => $clinicId, 'date' => $date ?? $now->toDateString()] + $row + ['created_at' => $now, 'updated_at' => $now]],
                ['clinic_id', 'date'],
                array_map(fn ($column) => DB::raw("$column + VALUES($column)"), array_combine(array_keys($row), array_keys($row)))
                    + ['updated_at' => $now],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
