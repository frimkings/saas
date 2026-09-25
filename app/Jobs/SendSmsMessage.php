<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\Messaging\SmsCreditService;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Tenant context travels in the queue payload and is re-authorized by AppServiceProvider. */
class SendSmsMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $smsLogId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(SmsService $sms): void
    {
        $log = SmsLog::find($this->smsLogId);

        if (!$log || $log->status !== 'queued') {
            return;
        }

        $result = $sms->deliver($log, finalAttempt: $this->attempts() >= $this->tries);

        // Gateway-rejected messages are final; only connection-level failures are retried.
        if (!$result['success'] && ($result['retryable'] ?? false) && $this->attempts() < $this->tries) {
            $log->update(['status' => 'queued']);
            $this->release($this->backoff()[$this->attempts() - 1] ?? 600);
        }
    }

    public function failed(\Throwable $e): void
    {
        SmsLog::whereKey($this->smsLogId)->where('status', 'queued')
            ->update(['status' => 'failed', 'success' => false, 'error' => $e->getMessage()]);

        if ($log = SmsLog::find($this->smsLogId)) {
            app(SmsCreditService::class)->refund($log);
        }
    }
}
