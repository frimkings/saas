<?php

namespace App\Services\Messaging;

use RuntimeException;

class InsufficientSmsCreditsException extends RuntimeException
{
    public function __construct(public readonly int $balance, public readonly int $needed)
    {
        parent::__construct($balance === 0
            ? 'Your clinic has no SMS credits left. Buy more in Settings → SMS.'
            : "This message needs {$needed} SMS credits but only {$balance} are left. Buy more in Settings → SMS.");
    }
}
