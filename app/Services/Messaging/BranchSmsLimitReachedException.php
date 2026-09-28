<?php

namespace App\Services\Messaging;

use RuntimeException;

/** The branch has used its share of the clinic's SMS; nothing is sent until the owner adds more. */
class BranchSmsLimitReachedException extends RuntimeException
{
    public function __construct(public readonly string $branchName, public readonly int $remaining, public readonly int $needed)
    {
        parent::__construct($remaining === 0
            ? "{$branchName} has used all its SMS. Ask the clinic owner to add more."
            : "This message needs {$needed} SMS but {$branchName} only has {$remaining} left. Ask the clinic owner to add more.");
    }
}
