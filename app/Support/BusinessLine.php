<?php

namespace App\Support;

/** The two businesses a subscriber can run. Their books are kept apart and reported per line. */
class BusinessLine
{
    public const CLINIC = 'clinic';
    public const OPTICAL = 'optical';

    public static function all(): array
    {
        return [self::CLINIC, self::OPTICAL];
    }
}
