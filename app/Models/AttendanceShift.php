<?php

namespace App\Models;

use App\Models\Concerns\AuditsAttendance;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A named shift at a branch. One that ends at or before it starts runs into the next morning. */
class AttendanceShift extends Model
{
    use AuditsAttendance, BelongsToBranch;

    protected $fillable = ['name', 'starts_at', 'ends_at', 'break_minutes', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'break_minutes' => 'integer'];

    public function overnight(): bool
    {
        return $this->ends_at <= $this->starts_at;
    }

    /**
     * The shift as working hours for a day. A night shift's end is counted past midnight
     * ('30:00' is 6 AM the next morning), so the whole shift belongs to the day it starts on.
     */
    public function hours(): array
    {
        $end = $this->overnight() ? sprintf('%02d:%s', (int) substr($this->ends_at, 0, 2) + 24, substr($this->ends_at, 3, 2)) : $this->ends_at;

        return ['start' => $this->starts_at, 'end' => $end, 'shift' => $this->name, 'break' => $this->break_minutes];
    }

    /** 'Night · 8:00 PM to 6:00 AM'. */
    public function label(): string
    {
        return $this->name.' · '.\App\Services\Attendance\AttendanceAnalyzer::time($this->starts_at).' to '.\App\Services\Attendance\AttendanceAnalyzer::time($this->ends_at);
    }
}
