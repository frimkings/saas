<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Days one person is excused, first to last day inclusive. */
class AttendanceLeave extends Model
{
    use BelongsToBranch;

    public const TYPES = ['annual' => 'Annual leave', 'sick' => 'Sick leave', 'permission' => 'Permission', 'other' => 'Other'];

    protected $fillable = ['attendance_staff_id', 'starts_on', 'ends_on', 'type', 'note', 'created_by'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function staff()
    {
        return $this->belongsTo(AttendanceStaff::class, 'attendance_staff_id');
    }
}
