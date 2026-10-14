<?php

namespace App\Support;

/** Whether attendance records note their changes in the audit trail one by one (see App\Models\Concerns\AuditsAttendance). */
final class AttendanceAudit
{
    public static int $quiet = 0;

    /** Run bulk work without an entry for every row it touches; the work notes a summary of its own. */
    public static function quietly(callable $work): mixed
    {
        self::$quiet++;
        try {
            return $work();
        } finally {
            self::$quiet--;
        }
    }
}
