<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CopyBackupToDrivesCommand extends Command
{
    protected $signature   = 'backup:copy-to-drives';
    protected $description = 'Copy the latest backup archive to all configured external drive destinations.';

    public function handle(): int
    {
        $extraPaths = Setting::getSettings()->backup_extra_paths ?? [];

        if (empty($extraPaths)) {
            $this->info('No extra destinations configured — nothing to copy.');
            cache()->put('backup_copy_results', [], now()->addMinutes(10));
            return 0;
        }

        $disk       = Storage::disk('backups');
        $backupName = config('backup.backup.name');

        $latest = collect($disk->files($backupName))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->sortByDesc(fn ($f) => $disk->lastModified($f))
            ->first();

        if (!$latest) {
            $this->warn('No backup archive found to copy.');
            return 1;
        }

        $filename = basename($latest);
        $results  = [];

        foreach ($extraPaths as $path) {
            if (!is_dir($path)) {
                $results[] = ['path' => $path, 'ok' => false, 'reason' => 'Drive not connected'];
                $this->warn("  Skipped: {$path} — drive not connected");
                continue;
            }

            if (!is_writable($path)) {
                $results[] = ['path' => $path, 'ok' => false, 'reason' => 'Not writable'];
                $this->warn("  Skipped: {$path} — not writable");
                continue;
            }

            try {
                $dest = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $filename;
                file_put_contents($dest, $disk->get($latest));
                $results[] = ['path' => $path, 'ok' => true, 'reason' => ''];
                $this->info("  Copied → {$dest}");
            } catch (\Throwable $e) {
                $results[] = ['path' => $path, 'ok' => false, 'reason' => $e->getMessage()];
                $this->error("  Failed: {$path} — {$e->getMessage()}");
            }
        }

        cache()->put('backup_copy_results', $results, now()->addMinutes(10));

        $ok   = count(array_filter($results, fn ($r) => $r['ok']));
        $fail = count($results) - $ok;
        $this->info("Done. Success: {$ok}  |  Failed: {$fail}");

        return $fail > 0 ? 1 : 0;
    }
}
