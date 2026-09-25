<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneLogsCommand extends Command
{
    protected $signature   = 'logs:prune {--dry-run : Show counts without moving any rows}';
    protected $description = 'Archive old log rows: login_logs (>90d), audit_trails (>365d), sms_logs (>180d).';

    private const TARGETS = [
        ['source' => 'login_logs',   'archive' => 'login_logs_archive',   'column' => 'login_at',   'days' => 90],
        ['source' => 'audit_trails', 'archive' => 'audit_trails_archive', 'column' => 'created_at', 'days' => 365],
        ['source' => 'sms_logs',     'archive' => 'sms_logs_archive',     'column' => 'created_at', 'days' => 180],
    ];

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        foreach (self::TARGETS as ['source' => $source, 'archive' => $archive, 'column' => $col, 'days' => $days]) {
            $cutoff = Carbon::now()->subDays($days);
            $count  = DB::table($source)->where($col, '<', $cutoff)->count();

            if ($dry) {
                $this->line("DRY RUN — {$source}: {$count} rows older than {$days} days would be archived to {$archive}.");
                continue;
            }

            $archived = $this->archiveTable($source, $archive, $col, $cutoff);

            $this->info("Archived {$source} → {$archive}: {$archived} rows moved (cutoff: {$cutoff->toDateString()}).");
            Log::info("logs:prune — {$source}: {$archived} rows archived to {$archive}.");
        }

        return self::SUCCESS;
    }

    private function archiveTable(string $source, string $archive, string $col, Carbon $cutoff): int
    {
        $archived = 0;

        do {
            $ids = DB::table($source)->where($col, '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // Copy batch to archive (insertOrIgnore handles re-runs gracefully)
            $rows = DB::table($source)->whereIn('id', $ids)->get()->map(fn ($r) => (array) $r)->toArray();
            DB::table($archive)->insertOrIgnore($rows);

            // Remove from source only after successful copy
            DB::table($source)->whereIn('id', $ids)->delete();

            $archived += count($ids);
        } while (count($ids) === 1000);

        return $archived;
    }
}
