<?php

namespace App\Support\Clinical;

/**
 * The doctor's note on a prescribed drug and its taper flag (24 Nov 2026). One place for the quick-pick
 * wording, the clean-up before saving and the line printed under the drug.
 */
final class DrugInstructions
{
    public const MAX = 500;

    /** Quick picks shown under a drug line; clicking one adds it to the note. */
    public const CHIPS = [
        'Taper as directed',
        'Do not stop suddenly',
        'Shake well before use',
        'Wait 5 minutes between different drops',
        'Take after meals',
        'Take with plenty of water',
        'Stop and return if the eye becomes painful',
    ];

    /** Trimmed, single-spaced and cut to the column size; null when empty. */
    public static function clean(mixed $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return $text === '' ? null : mb_substr($text, 0, self::MAX);
    }

    /** Adds a quick pick to a note unless it is already there. */
    public static function append(?string $note, string $chip): string
    {
        $note = (string) self::clean($note);
        if ($note !== '' && mb_stripos($note, $chip) !== false) return $note;

        return (string) self::clean($note === '' ? $chip : rtrim($note, " .") . '. ' . $chip);
    }

    /** "TAPER · Reduce by one drop a week" for printing; null when there is nothing to say. */
    public static function line(?string $instructions, mixed $taper): ?string
    {
        $parts = array_filter([(bool) $taper ? 'TAPER' : null, self::clean($instructions)]);

        return $parts ? implode(' · ', $parts) : null;
    }
}
