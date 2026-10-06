<?php

namespace App\Livewire\Platform;

use App\Support\Usage\UsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Platform → Usage: each clinic's requests, data, server time and stored rows over a period,
 * its share of the platform's server time, and that share of the hosting bill.
 */
class ClinicUsageComponent extends Component
{
    public const PERIODS = ['this_month' => 'This month', 'last_month' => 'Last month', 'last_30' => 'Last 30 days', 'last_7' => 'Last 7 days'];
    public const SORTS = ['server_ms', 'requests', 'bytes_out', 'db_ms', 'stored_rows', 'name'];

    #[Url]
    public string $period = 'this_month';
    #[Url]
    public string $sort = 'server_ms';
    /** The hosting bill for the period, to split by server time. Not saved. */
    public string $hostingCost = '';
    /** Clinic whose day-by-day usage is open. */
    public ?int $clinicId = null;

    public function mount(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) $this->period = 'this_month';
        if (! in_array($this->sort, self::SORTS, true)) $this->sort = 'server_ms';
    }

    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) $this->period = 'this_month';
    }

    public function sortBy(string $column): void
    {
        if (in_array($column, self::SORTS, true)) $this->sort = $column;
    }

    public function showClinic(?int $id): void
    {
        $this->clinicId = $this->clinicId === $id ? null : $id;
    }

    /** [first day, last day] of the chosen period. */
    public function range(): array
    {
        $today = CarbonImmutable::today();

        return match ($this->period) {
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_30' => [$today->subDays(29), $today],
            'last_7' => [$today->subDays(6), $today],
            default => [$today->startOfMonth(), $today],
        };
    }

    private function clinics(array $range): Collection
    {
        [$from, $to] = array_map(fn ($d) => $d->toDateString(), $range);

        $usage = DB::table('clinic_usage_daily')->whereBetween('date', [$from, $to])->groupBy('clinic_id')
            ->selectRaw('clinic_id, SUM(requests) AS requests, SUM(page_views) AS page_views, SUM(actions) AS actions,
                SUM(bytes_out) AS bytes_out, SUM(bytes_in) AS bytes_in, SUM(server_ms) AS server_ms, SUM(db_ms) AS db_ms,
                SUM(db_queries) AS db_queries, SUM(CASE WHEN requests > 0 THEN 1 ELSE 0 END) AS active_days')
            ->get()->keyBy('clinic_id');

        // Stored rows: each clinic's latest snapshot in the period.
        $latest = DB::table('clinic_usage_daily')->whereBetween('date', [$from, $to])->whereNotNull('stored_rows')
            ->groupBy('clinic_id')->selectRaw('clinic_id, MAX(date) AS date');
        $stored = DB::table('clinic_usage_daily AS u')->joinSub($latest, 'l', fn ($j) => $j->on('u.clinic_id', '=', 'l.clinic_id')->on('u.date', '=', 'l.date'))
            ->pluck('u.stored_rows', 'u.clinic_id');

        $totalServer = max(1, (int) $usage->sum('server_ms'));
        $cost = is_numeric($this->hostingCost) ? (float) $this->hostingCost : null;

        $rows = DB::table('clinics')->where('status', 'active')->orWhereIn('id', $usage->keys())->orderBy('name')->get(['id', 'name', 'status'])
            ->map(function ($clinic) use ($usage, $stored, $totalServer, $cost) {
                $u = $usage->get($clinic->id);
                $row = ['id' => (int) $clinic->id, 'name' => $clinic->name, 'status' => $clinic->status];
                foreach (['requests', 'page_views', 'actions', 'bytes_out', 'bytes_in', 'server_ms', 'db_ms', 'db_queries', 'active_days'] as $k) {
                    $row[$k] = (int) ($u->$k ?? 0);
                }
                $row['stored_rows'] = isset($stored[$clinic->id]) ? (int) $stored[$clinic->id] : null;
                $row['share'] = $row['server_ms'] / $totalServer;
                $row['cost'] = $cost !== null ? $cost * $row['share'] : null;

                return $row;
            });

        return $this->sort === 'name'
            ? $rows->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $rows->sortByDesc($this->sort)->values();
    }

    private function days(array $range): Collection
    {
        if (! $this->clinicId) return collect();

        return DB::table('clinic_usage_daily')->where('clinic_id', $this->clinicId)
            ->whereBetween('date', array_map(fn ($d) => $d->toDateString(), $range))->orderByDesc('date')->get();
    }

    public static function bytes(int|float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') return ($unit === 'B' ? $bytes : number_format($bytes, 1)).' '.$unit;
            $bytes /= 1024;
        }
    }

    public static function duration(int|float $ms): string
    {
        if ($ms < 1000) return round($ms).' ms';
        if ($ms < 60_000) return number_format($ms / 1000, 1).' s';
        if ($ms < 3_600_000) return number_format($ms / 60_000, 1).' min';

        return number_format($ms / 3_600_000, 1).' h';
    }

    public function render()
    {
        $range = $this->range();
        $clinics = $this->clinics($range);

        return view('livewire.platform.clinic-usage-component', [
            'range' => $range,
            'clinics' => $clinics,
            'totals' => [
                'requests' => $clinics->sum('requests'), 'bytes_out' => $clinics->sum('bytes_out'),
                'server_ms' => $clinics->sum('server_ms'), 'active' => $clinics->where('requests', '>', 0)->count(),
            ],
            'days' => $this->days($range),
            'openClinic' => $clinics->firstWhere('id', $this->clinicId),
            'metering' => UsageMeter::enabled(),
        ])->layout('layouts.platform');
    }
}
