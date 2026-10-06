<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps log tables small for every clinic:
 *  - login history: 30 days, then deleted;
 *  - audit trail: routine events (views, exports) 30 days; financial and record changes 1 year; then deleted;
 *  - SMS logs: moved to sms_logs_archive after 180 days (unchanged).
 * The old login/audit archive tables are emptied the same way and no longer written to.
 */
class PruneLogsCommand extends Command
{
    protected $signature   = 'logs:prune {--dry-run : Show counts without changing any rows}';
    protected $description = 'Delete login history over 30 days and audit events past their retention; archive SMS logs over 180 days.';

    public const LOGIN_DAYS = 30;
    public const AUDIT_ROUTINE_DAYS = 30;
    public const AUDIT_CHANGE_DAYS = 365;

    /** Event name endings that only record someone looking at or exporting data. */
    public const ROUTINE_EVENT_SUFFIXES = ['.accessed', '.viewed', '.exported', '.printed', '.downloaded'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $loginCutoff   = Carbon::now()->subDays(self::LOGIN_DAYS);
        $routineCutoff = Carbon::now()->subDays(self::AUDIT_ROUTINE_DAYS);
        $changeCutoff  = Carbon::now()->subDays(self::AUDIT_CHANGE_DAYS);

        foreach (['login_logs', 'login_logs_archive'] as $table) {
            $this->purge($table, fn (Builder $q) => $q->where('login_at', '<', $loginCutoff), $dry,
                'older than ' . self::LOGIN_DAYS . ' days');
        }

        foreach (['audit_trails', 'audit_trails_archive'] as $table) {
            $this->purge($table, fn (Builder $q) => $q->where('created_at', '<', $routineCutoff)->where(function (Builder $events) {
                foreach (self::ROUTINE_EVENT_SUFFIXES as $suffix) {
                    $events->orWhere('event', 'like', '%' . $suffix);
                }
            }), $dry, 'routine events older than ' . self::AUDIT_ROUTINE_DAYS . ' days');

            $this->purge($table, fn (Builder $q) => $q->where('created_at', '<', $changeCutoff), $dry,
                'older than ' . self::AUDIT_CHANGE_DAYS . ' days');
        }

        $smsCutoff = Carbon::now()->subDays(180);
        $smsCount = DB::table('sms_logs')->where('created_at', '<', $smsCutoff)->count();
        if ($dry) {
            $this->line("DRY RUN — sms_logs: {$smsCount} rows older than 180 days would be archived.");
        } else {
            $moved = $this->archiveSms($smsCutoff);
            $this->info("sms_logs: {$moved} rows archived (cutoff {$smsCutoff->toDateString()}).");
            Log::info("logs:prune — sms_logs: {$moved} rows archived to sms_logs_archive.");
        }

        return self::SUCCESS;
    }

    /** Deletes matching rows in batches so the nightly run never holds long locks. */
    private function purge(string $table, \Closure $scope, bool $dry, string $what): void
    {
        $query = fn () => tap(DB::table($table), $scope);

        if ($dry) {
            $count = $query()->count();
            $this->line("DRY RUN — {$table}: {$count} rows {$what} would be deleted.");
            return;
        }

        $deleted = 0;
        do {
            $ids = $query()->orderBy('id')->limit(1000)->pluck('id');
            if ($ids->isNotEmpty()) {
                $deleted += DB::table($table)->whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === 1000);

        $this->info("{$table}: {$deleted} rows {$what} deleted.");
        if ($deleted > 0) {
            Log::info("logs:prune — {$table}: {$deleted} rows {$what} deleted.");
        }
    }

    private function archiveSms(Carbon $cutoff): int
    {
        $archived = 0;
        do {
            $ids = DB::table('sms_logs')->where('created_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $rows = DB::table('sms_logs')->whereIn('id', $ids)->get()->map(fn ($r) => (array) $r)->toArray();
            DB::table('sms_logs_archive')->insertOrIgnore($rows);
            DB::table('sms_logs')->whereIn('id', $ids)->delete();
            $archived += count($ids);
        } while (count($ids) === 1000);

        return $archived;
    }
}
