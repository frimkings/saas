<?php

namespace App\Support;

/**
 * File size limits. Every clinic upload (patient documents, receipts, logos, imports) is held to
 * one per-file limit; only the platform's legacy database import may send large files.
 */
class UploadLimits
{
    /** Per file, in kilobytes (Laravel's "max" rule for files). */
    public const CLINIC_KB = 3072;

    /** The platform legacy import's SQL dumps. */
    public const PLATFORM_IMPORT_KB = 204800;

    /** The "max" rule for a clinic upload, e.g. 'file|'.UploadLimits::rule(). */
    public static function rule(): string
    {
        return 'max:'.self::CLINIC_KB;
    }

    /** "3 MB", for labels and messages. */
    public static function label(int $kb = self::CLINIC_KB): string
    {
        return rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.').' MB';
    }
}
