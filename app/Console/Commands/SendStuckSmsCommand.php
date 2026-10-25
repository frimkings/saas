<?php

namespace App\Console\Commands;

use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Console\Command;

/**
 * The safety net under the managed queue: an SMS still waiting long after it was queued (the
 * queue was down, misconfigured or never started) is sent directly, so no message sits forever
 * with its credit taken. Runs per clinic through tenancy:run-scheduled.
 *
 * The wait is longer than the queue job's own retries (30 s, 2 min, 10 min), so a message the
 * queue is still retrying is left to it. Each message is claimed first, so two runs never send it twice.
 */
class SendStuckSmsCommand extends Command
{
    protected $signature = 'sms:send-stuck {--minutes=20 : How long a message waits before it counts as stuck} {--limit=200}';
    protected $description = 'Send SMS that have waited in the queue too long, directly';

    public function handle(SmsService $sms): int
    {
        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $sent = $failed = 0;

        SmsLog::query()->where('channel', 'sms')->where('status', 'queued')->where('updated_at', '<', $cutoff)
            ->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get()
            ->each(function (SmsLog $log) use ($sms, $cutoff, &$sent, &$failed) {
                // Claim it: only the run that moves its timestamp on sends it.
                $claimed = SmsLog::query()->whereKey($log->id)->where('status', 'queued')->where('updated_at', '<', $cutoff)
                    ->update(['updated_at' => now()]);
                if (! $claimed) return;

                $sms->deliver($log->fresh())['success'] ? $sent++ : $failed++;
            });

        if ($sent || $failed) $this->line("Stuck SMS sent directly: {$sent} sent, {$failed} failed.");

        return self::SUCCESS;
    }
}
