<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PruneBackupsCommand extends Command
{
    protected $signature   = 'backup:prune-custom';
    protected $description = 'Retain latest per day this week, per week this month, per month this year, per year beyond.';

    public function handle(): int
    {
        $disk   = Storage::disk('backups');
        $folder = config('backup.backup.name');
        $now    = Carbon::now();

        $all = collect($disk->files($folder))
            ->filter(fn ($f) => str_ends_with($f, '.zip') || str_ends_with($f, '.sql'))
            ->map(fn ($f) => [
                'path' => $f,
                'ts'   => $disk->lastModified($f),
                'dt'   => Carbon::createFromTimestamp($disk->lastModified($f)),
            ])
            ->sortByDesc('ts')
            ->values();

        if ($all->isEmpty()) {
            $this->info('No backups to prune.');
            return 0;
        }

        $today            = $now->toDateString();
        $startOfThisWeek  = $now->copy()->startOfWeek();
        $startOfThisMonth = $now->copy()->startOfMonth();
        $startOfThisYear  = $now->copy()->startOfYear();

        // Today — keep every backup (fresh 5-min snapshots)
        $todaysFiles = $all->filter(fn ($f) => $f['dt']->toDateString() === $today);

        // This week (not today) — keep latest per day
        $thisWeekOlder = $all->filter(
            fn ($f) => $f['dt']->gte($startOfThisWeek) && $f['dt']->toDateString() !== $today
        );

        // This month (not this week) — keep latest per ISO week
        $thisMonthOlder = $all->filter(
            fn ($f) => $f['dt']->gte($startOfThisMonth) && $f['dt']->lt($startOfThisWeek)
        );

        // This year (not this month) — keep latest per month
        $thisYearOlder = $all->filter(
            fn ($f) => $f['dt']->gte($startOfThisYear) && $f['dt']->lt($startOfThisMonth)
        );

        // Before this year — keep latest per year
        $beforeThisYear = $all->filter(fn ($f) => $f['dt']->lt($startOfThisYear));

        $keep   = $todaysFiles->pluck('path')->all();
        $delete = [];

        $this->applyTier($thisWeekOlder,  fn ($f) => $f['dt']->toDateString(),  $keep, $delete);
        $this->applyTier($thisMonthOlder, fn ($f) => $f['dt']->format('Y-W'),   $keep, $delete);
        $this->applyTier($thisYearOlder,  fn ($f) => $f['dt']->format('Y-m'),   $keep, $delete);
        $this->applyTier($beforeThisYear, fn ($f) => $f['dt']->format('Y'),     $keep, $delete);

        $deleted = 0;
        foreach (array_unique($delete) as $path) {
            if (!in_array($path, $keep, true)) {
                $disk->delete($path);
                $this->line("  Deleted: {$path}");
                $deleted++;
            }
        }

        $kept = count(array_unique($keep));
        $this->info("Done. Kept: {$kept}  |  Deleted: {$deleted}");
        return 0;
    }

    private function applyTier(Collection $files, callable $groupKey, array &$keep, array &$delete): void
    {
        foreach ($files->groupBy($groupKey) as $group) {
            $sorted = $group->sortByDesc('ts')->values();
            $keep[] = $sorted->first()['path'];
            foreach ($sorted->skip(1) as $f) {
                $delete[] = $f['path'];
            }
        }
    }
}
