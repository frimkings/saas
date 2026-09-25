<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Tenant context travels in the queue payload and is re-authorized by AppServiceProvider. */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public int $smsLogId,
        public string $templateName,
        public string $languageCode,
        public array $bodyParams,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(WhatsAppService $whatsApp): void
    {
        $log = SmsLog::find($this->smsLogId);

        if (!$log || $log->status !== 'queued') {
            return;
        }

        $result = $whatsApp->deliverTemplate($log, $this->templateName, $this->languageCode, $this->bodyParams);

        // Meta-rejected messages are final; only connection-level failures are retried.
        if (!$result['success'] && ($result['retryable'] ?? false) && $this->attempts() < $this->tries) {
            $log->update(['status' => 'queued']);
            $this->release($this->backoff()[$this->attempts() - 1] ?? 600);
        }
    }

    public function failed(\Throwable $e): void
    {
        SmsLog::whereKey($this->smsLogId)->where('status', 'queued')
            ->update(['status' => 'failed', 'success' => false, 'error' => $e->getMessage()]);
    }
}
