<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    /**
     * Send a Mailable, swallowing any exception so callers stay fire-and-forget.
     * Returns true on success, false on failure.
     */
    public function send(string $to, \Illuminate\Mail\Mailable $mailable): bool
    {
        if (empty(trim($to))) {
            return false;
        }

        try {
            Mail::to($to)->send($mailable);
            return true;
        } catch (\Throwable $e) {
            Log::warning('EmailService failed', ['to' => $to, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
