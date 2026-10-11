<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Services\Attendance\AttendanceAnalyzer;
use Illuminate\Database\Eloquent\Model;

/**
 * Time one person is excused: whole days, first to last inclusive, or part of one day
 * (from_time to to_time). Staff can ask for it themselves; it only counts once approved.
 */
class AttendanceLeave extends Model
{
    use BelongsToBranch;

    public const TYPES = ['annual' => 'Annual leave', 'sick' => 'Sick leave', 'permission' => 'Permission', 'other' => 'Other'];

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const STATUSES = [self::PENDING => 'Waiting for approval', self::APPROVED => 'Approved', self::REJECTED => 'Not approved'];

    protected $fillable = ['attendance_staff_id', 'starts_on', 'ends_on', 'from_time', 'to_time', 'type', 'note', 'status',
        'created_by', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected $attributes = ['status' => self::APPROVED];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(AttendanceStaff::class, 'attendance_staff_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::APPROVED);
    }

    public function isPartDay(): bool
    {
        return $this->from_time && $this->to_time;
    }

    /** "12 Oct 2026 to 14 Oct 2026", or "12 Oct 2026, 8:00 AM to 12:00 PM" for part of a day. */
    public function when(): string
    {
        if ($this->isPartDay()) {
            return $this->starts_on->format('j M Y').', '.AttendanceAnalyzer::time($this->from_time).' to '.AttendanceAnalyzer::time($this->to_time);
        }

        return $this->starts_on->format('j M Y').($this->ends_on->ne($this->starts_on) ? ' to '.$this->ends_on->format('j M Y') : '');
    }

    public function typeName(): string
    {
        return self::TYPES[$this->type] ?? 'Leave';
    }
}
