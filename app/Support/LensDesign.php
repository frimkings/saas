<?php

namespace App\Support;

/**
 * Finished progressive and flat-top bifocal lenses are made for one eye: the
 * near zone is set toward the nose. They are stocked, counted and matched per
 * eye (R/L) and keyed by SPH + ADD. Single vision lenses fit either eye and
 * are keyed by SPH + CYL.
 */
final class LensDesign
{
    public const EYE_SPECIFIC = ['Progressive', 'Bifocal'];

    public const EYES = ['R' => 'Right (OD)', 'L' => 'Left (OS)'];

    public static function isEyeSpecific(?string $design): bool
    {
        return in_array($design, self::EYE_SPECIFIC, true);
    }

    /** Order-form eye key (od/os) to the stock lens eye (R/L). */
    public static function stockEye(string $eye): string
    {
        return $eye === 'od' ? 'R' : 'L';
    }
}
