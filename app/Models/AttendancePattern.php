<?php

namespace App\Models;

use App\Models\Concerns\AuditsAttendance;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A saved rotation: the shift (or 0 for a day off) on each day of a cycle that repeats. */
class AttendancePattern extends Model
{
    use AuditsAttendance, BelongsToBranch;

    protected $fillable = ['name', 'days'];

    protected $casts = ['days' => 'array'];

    protected function auditNoun(): string
    {
        return 'rotation pattern';
    }
}
