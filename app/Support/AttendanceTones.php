<?php

namespace App\Support;

/**
 * One set of colours for what a day was (on time, late, absent…), what to check on it (left early,
 * a long break…) and where someone is right now, for every attendance screen and printout, so late
 * is the same amber everywhere. Days are filled in; things to check are outlined, so a late day
 * that also left early reads as two different things. Hex, not classes, so the PDFs use them too.
 */
final class AttendanceTones
{
    /** Status => [background, text]. */
    public const STATUS = [
        'on_time' => ['#d1fae5', '#065f46'],
        'late' => ['#fef3c7', '#78350f'],
        'absent' => ['#fee2e2', '#991b1b'],
        'leave' => ['#e0e7ff', '#3730a3'],
        'holiday' => ['#ede9fe', '#5b21b6'],
        'worked_off' => ['#e0f2fe', '#075985'],
        'off' => ['#f1f5f9', '#64748b'],
        'none' => ['#f1f5f9', '#64748b'],
    ];

    /** Something to check on a day => [line, text, label]. */
    public const FLAG = [
        'left_early' => ['#ea580c', '#9a3412', 'Left early'],
        'long_break' => ['#ca8a04', '#854d0e', 'Long break'],
        'no_clock_out' => ['#64748b', '#334155', 'Missing punch'],
        'overtime' => ['#0d9488', '#115e59', 'Overtime'],
        'overtime_pending' => ['#0d9488', '#115e59', 'Overtime waiting'],
        'overtime_declined' => ['#94a3b8', '#64748b', 'Overtime not approved'],
        'note' => ['#6366f1', '#3730a3', 'Note'],
    ];

    /** Where someone is on the Today board => the status it looks like. */
    public const NOW = [
        'in' => 'on_time',
        'missing' => 'absent',
        'due' => 'off',
        'out' => 'off',
        'leave' => 'leave',
        'holiday' => 'holiday',
        'off' => 'off',
    ];

    /** Inline style for a status badge. */
    public static function status(string $status): string
    {
        [$background, $colour] = self::STATUS[$status] ?? self::STATUS['none'];

        return "background-color:{$background};color:{$colour}";
    }

    /** Inline style for a thing to check: white with a coloured outline, dashed while waiting. */
    public static function flag(string $flag): string
    {
        [$line, $colour] = self::FLAG[$flag] ?? self::FLAG['note'];
        $dash = $flag === 'overtime_pending' ? 'dashed' : 'solid';

        return "background-color:#ffffff;color:{$colour};border:1px {$dash} {$line}";
    }

    /** The flag key for a day's overtime: overtime, overtime_pending or overtime_declined. */
    public static function overtime(?string $status): string
    {
        return ['pending' => 'overtime_pending', 'declined' => 'overtime_declined'][$status] ?? 'overtime';
    }

    /** Coloured left edge for someone on the Today board. */
    public static function edge(string $now): string
    {
        $status = self::NOW[$now] ?? 'off';

        return 'border-left:4px solid '.($status === 'off' ? '#cbd5e1' : self::STATUS[$status][1]);
    }

    /** Background only, for a filled cell in a printed grid. */
    public static function shade(string $status): string
    {
        return (self::STATUS[$status] ?? self::STATUS['none'])[0];
    }
}
