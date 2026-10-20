<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Short codes and colours for a branch's shifts, for the roster grid, its Excel file, its printout
 * and wherever a shift is shown. A shift can be given its own code and colour; otherwise "Morning"
 * is M, "Night Shift" NS (two shifts that would share a code take more letters) and the colour
 * comes from the list below, one not already taken. A day off is OFF.
 */
final class ShiftCodes
{
    public const OFF = 'OFF';

    /** What a code can't be: these mean a day off, usual hours or leave in the grid and the file. */
    public const RESERVED = [self::OFF, 'O', 'X', 'USUAL', 'LV', 'HRS'];

    /** The colours offered for a shift, strong enough to tell apart on a printed roster. */
    public const COLOURS = [
        '#0d9488' => 'Teal', '#2563eb' => 'Blue', '#4f46e5' => 'Indigo', '#9333ea' => 'Purple',
        '#db2777' => 'Pink', '#dc2626' => 'Red', '#ea580c' => 'Orange', '#f59e0b' => 'Amber',
        '#facc15' => 'Yellow', '#84cc16' => 'Lime', '#16a34a' => 'Green', '#06b6d4' => 'Cyan',
        '#92400e' => 'Brown', '#475569' => 'Slate', '#1e3a8a' => 'Navy', '#9f1239' => 'Maroon',
    ];

    /** Shift id => ['code', 'name', 'background', 'colour', 'style'], in starting-time order. */
    public static function for(Collection $shifts): array
    {
        $sorted = $shifts->sortBy(fn ($shift) => [$shift->starts_at, $shift->name])->values();
        // A shift's own code and colour first, so the worked-out ones keep clear of them.
        $taken = array_fill_keys(self::RESERVED, true);
        foreach ($sorted as $shift) {
            if ($shift->code) {
                $taken[strtoupper($shift->code)] = true;
            }
        }
        $free = array_values(array_diff(array_keys(self::COLOURS), $sorted->pluck('colour')->filter()->map(fn ($hex) => strtolower($hex))->all()));
        $palette = $free ?: array_keys(self::COLOURS);

        $codes = [];
        $auto = 0;
        foreach ($sorted as $shift) {
            $code = $shift->code ? strtoupper($shift->code) : self::workedOut($shift->name, $taken);
            $taken[$code] = true;
            $background = $shift->colour ? strtolower($shift->colour) : $palette[$auto++ % count($palette)];
            $colour = self::textOn($background);
            $codes[$shift->id] = ['code' => $code, 'name' => $shift->name, 'background' => $background, 'colour' => $colour,
                'style' => "background-color:{$background};color:{$colour}"];
        }

        return $codes;
    }

    /** "Morning" is M, "Night Shift" NS; more letters, then a number, when that is taken. */
    private static function workedOut(string $name, array $taken): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['S'];
        $code = substr(strtoupper(implode('', array_map(fn ($word) => $word[0], $words))), 0, 4);
        $letters = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name)) ?: 'S';
        for ($n = 2; isset($taken[$code]) && $n <= min(4, strlen($letters)); $n++) {
            $code = substr($letters, 0, $n);
        }
        for ($n = 2; isset($taken[$code]); $n++) {
            $code = substr($letters, 0, 2).$n;
        }

        return $code;
    }

    /** White or near-black text, whichever reads better on the colour. */
    public static function textOn(string $hex): string
    {
        $channel = function (string $pair) {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };
        $hex = ltrim($hex, '#');
        $light = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));

        return 1.05 / ($light + 0.05) >= ($light + 0.05) / 0.0589 ? '#ffffff' : '#0f172a';
    }

    /**
     * What a cell in a roster file means: a shift id, 0 for a day off, 'usual' to go back to the
     * person's usual days, null for no change (an empty cell), or false when not understood.
     */
    public static function read(string $cell, array $codes): int|string|null|false
    {
        $cell = trim($cell);
        if ($cell === '') {
            return null;
        }
        $upper = strtoupper($cell);
        if (in_array($upper, [self::OFF, 'O', 'DAY OFF', '-', 'X'], true)) {
            return 0;
        }
        if (in_array($upper, ['USUAL', 'AS USUAL'], true)) {
            return 'usual';
        }
        foreach ($codes as $id => $shift) {
            if ($upper === $shift['code'] || $upper === strtoupper($shift['name'])) {
                return $id;
            }
        }

        return false;
    }
}
