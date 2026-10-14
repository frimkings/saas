<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** Someone enrolled on a branch's attendance terminal, known by their Employee ID there. */
class AttendanceStaff extends Model
{
    use BelongsToBranch;

    protected $table = 'attendance_staff';

    protected $fillable = ['employee_no', 'name', 'department', 'user_id', 'is_tracked', 'starts_on', 'hours', 'shift_week', 'pay_type', 'pay_rate', 'attendance_department_id'];

    protected $casts = [
        'is_tracked' => 'boolean',
        'starts_on' => 'date',
        // Working hours of their own, shaped like AttendanceSetting::hours; null = the branch's.
        'hours' => 'array',
        // ISO weekday => shift id, or 0 for a day off; a weekday left out follows the usual hours.
        'shift_week' => 'array',
    ];

    protected static function booted(): void
    {
        // Someone new once the plan's limit is reached is kept, with their punches, but not followed.
        static::creating(function (self $staff): void {
            if (($staff->is_tracked ?? true) && ! \App\Support\AttendancePlan::canFollow(1, $staff->clinic_id ? \App\Models\Clinic::find($staff->clinic_id) : null)) {
                $staff->is_tracked = false;
            }
        });
    }

    public function unit()
    {
        return $this->belongsTo(AttendanceDepartment::class, 'attendance_department_id');
    }

    public function punches()
    {
        return $this->hasMany(AttendancePunch::class);
    }

    public function leaves()
    {
        return $this->hasMany(AttendanceLeave::class);
    }
}
