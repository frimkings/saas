<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** One person's shift on one date, overriding their weekly pattern; no shift means a day off. */
class AttendanceRoster extends Model
{
    use BelongsToBranch;

    protected $table = 'attendance_roster';

    protected $fillable = ['attendance_staff_id', 'date', 'attendance_shift_id', 'note', 'set_by'];

    protected $casts = ['date' => 'date'];

    public function shift()
    {
        return $this->belongsTo(AttendanceShift::class, 'attendance_shift_id');
    }
}
