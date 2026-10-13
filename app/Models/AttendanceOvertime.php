<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/** A manager's answer on one person's overtime on one date: approved for so many minutes, or not. */
class AttendanceOvertime extends Model
{
    use BelongsToBranch;

    public const APPROVED = 'approved';
    public const DECLINED = 'declined';

    protected $table = 'attendance_overtime';

    protected $fillable = ['attendance_staff_id', 'date', 'status', 'minutes', 'note', 'decided_by'];

    protected $casts = ['date' => 'date', 'minutes' => 'integer'];

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function staff()
    {
        return $this->belongsTo(AttendanceStaff::class, 'attendance_staff_id');
    }
}
