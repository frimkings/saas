<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** How many of a department's people must be on a shift each day. */
class AttendanceCover extends Model
{
    use BelongsToBranch;

    protected $table = 'attendance_cover';

    protected $fillable = ['attendance_department_id', 'attendance_shift_id', 'minimum', 'weekend_minimum'];

    protected $casts = ['minimum' => 'integer', 'weekend_minimum' => 'integer'];

    public function department()
    {
        return $this->belongsTo(AttendanceDepartment::class, 'attendance_department_id');
    }

    public function shift()
    {
        return $this->belongsTo(AttendanceShift::class, 'attendance_shift_id');
    }

    public function neededOn(CarbonInterface $day): int
    {
        return $day->isWeekend() ? ($this->weekend_minimum ?? $this->minimum) : $this->minimum;
    }
}
