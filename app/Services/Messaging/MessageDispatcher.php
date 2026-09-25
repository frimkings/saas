<?php

namespace App\Services\Messaging;

use App\Services\ClinicAccessService;

class MessageDispatcher
{
    /**
     * Hosted clinics send through the queue so a slow gateway never blocks a request.
     * Offline/desktop installs usually run no queue worker, so they keep sending inline.
     * The sync driver is also sent inline: its Queue::after hook clears the tenant
     * context, which would strand the rest of the current request.
     */
    public function queues(): bool
    {
        return app(ClinicAccessService::class)->hosted()
            && config('queue.connections.'.config('queue.default').'.driver') !== 'sync';
    }

    public function dispatch(object $job): void
    {
        // afterCommit: the job only runs once the log row (and any surrounding booking) is committed.
        dispatch($job)->afterCommit();
    }
}
