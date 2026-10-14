<?php

namespace App\Models;

use App\Models\Concerns\AuditsAttendance;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** One person's shift on one date, overriding their weekly pattern; no shift means a day off. */
class AttendanceRoster extends Model
{
    use AuditsAttendance, BelongsToBranch;

    protected $table = 'attendance_roster';

    protected $fillable = ['attendance_staff_id', 'date', 'attendance_shift_id', 'note', 'set_by'];

    protected $casts = ['date' => 'date'];

    public function shift()
    {
        return $this->belongsTo(AttendanceShift::class, 'attendance_shift_id');
    }

    /** One person's day set by hand on the roster; bulk fills and swaps note a summary instead. */
    protected function auditEntry(string $verb, array $fields): ?array
    {
        $who = AttendanceStaff::query()->find($this->attendance_staff_id)?->name ?? 'someone';
        $day = \Carbon\Carbon::parse($this->date)->format('D j M Y');
        if ($verb === 'removed') {
            return ['roster_changed', "Put {$who} back to their usual days on {$day}"];
        }
        $shift = $this->attendance_shift_id ? (AttendanceShift::query()->find($this->attendance_shift_id)?->name ?? 'a shift') : 'a day off';

        return ['roster_changed', "Rostered {$who} on {$day}: {$shift}"];
    }
}
