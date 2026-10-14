<?php

namespace App\Models;

use App\Models\Concerns\AuditsAttendance;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A day the branch is closed: nobody is absent on it. */
class AttendanceHoliday extends Model
{
    use AuditsAttendance, BelongsToBranch;

    protected $fillable = ['date', 'name'];

    protected $casts = ['date' => 'date'];

    protected function auditLabel(): string
    {
        return $this->name.' ('.\Carbon\Carbon::parse($this->date)->format('j M Y').')';
    }
}
