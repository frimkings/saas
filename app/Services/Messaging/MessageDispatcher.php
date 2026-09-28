<?php

namespace App\Services\Messaging;

use App\Services\ClinicAccessService;
use Illuminate\Support\Facades\Cache;

class MessageDispatcher
{
    /** Set by running queue workers (see AppServiceProvider); a worker silent this long counts as stopped. */
    public const WORKER_HEARTBEAT = 'queue.worker_heartbeat';
    private const WORKER_STALE_SECONDS = 90;

    /**
     * Hosted clinics send through the queue so a slow gateway never blocks a request, but only
     * while a worker is running: with none, a queued SMS would sit forever with its credit taken.
     * Offline/desktop installs usually run no queue worker, so they keep sending inline.
     * The sync driver is also sent inline: its Queue::after hook clears the tenant
     * context, which would strand the rest of the current request.
     */
    public function queues(): bool
    {
        return app(ClinicAccessService::class)->hosted()
            && config('queue.connections.'.config('queue.default').'.driver') !== 'sync'
            && $this->workerRunning();
    }

    public function workerRunning(): bool
    {
        try {
            $seen = (int) Cache::get(self::WORKER_HEARTBEAT, 0);
        } catch (\Throwable) {
            return false;
        }

        return $seen >= now()->getTimestamp() - self::WORKER_STALE_SECONDS;
    }

    public function dispatch(object $job): void
    {
        // afterCommit: the job only runs once the log row (and any surrounding booking) is committed.
        dispatch($job)->afterCommit();
    }
}
