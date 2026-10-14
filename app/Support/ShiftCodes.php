<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Short codes and colours for a branch's shifts, for the roster grid, its Excel file and its
 * printout: "Morning" is M, "Night Shift" NS; two shifts that would share a code take more
 * letters. A day off is OFF.
 */
final class ShiftCodes
{
    public const OFF = 'OFF';

    /** Background and text colour pairs, for the screen and for print. */
    private const TONES = [
        ['bg-emerald-100 text-emerald-900', '#d1fae5', '#064e3b'],
        ['bg-sky-100 text-sky-900', '#e0f2fe', '#0c4a6e'],
        ['bg-violet-100 text-violet-900', '#ede9fe', '#4c1d95'],
        ['bg-amber-100 text-amber-900', '#fef3c7', '#78350f'],
        ['bg-rose-100 text-rose-900', '#ffe4e6', '#881337'],
        ['bg-teal-100 text-teal-900', '#ccfbf1', '#134e4a'],
        ['bg-lime-100 text-lime-900', '#ecfccb', '#365314'],
        ['bg-fuchsia-100 text-fuchsia-900', '#fae8ff', '#701a75'],
    ];

    /** Shift id => ['code', 'name', 'class', 'background', 'colour'], in starting-time order. */
    public static function for(Collection $shifts): array
    {
        $codes = [];
        $taken = [self::OFF => true];
        foreach ($shifts->sortBy(fn ($shift) => [$shift->starts_at, $shift->name])->values() as $i => $shift) {
            $words = preg_split('/[^A-Za-z0-9]+/', trim($shift->name), -1, PREG_SPLIT_NO_EMPTY) ?: ['S'];
            $code = strtoupper(implode('', array_map(fn ($word) => $word[0], $words)));
            $letters = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $shift->name)) ?: 'S';
            for ($n = 2; isset($taken[$code]) && $n <= strlen($letters); $n++) {
                $code = substr($letters, 0, $n);
            }
            for ($n = 2; isset($taken[$code]); $n++) {
                $code = substr($letters, 0, 2).$n;
            }
            $taken[$code] = true;
            [$class, $background, $colour] = self::TONES[$i % count(self::TONES)];
            $codes[$shift->id] = ['code' => $code, 'name' => $shift->name, 'class' => $class, 'background' => $background, 'colour' => $colour];
        }

        return $codes;
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
