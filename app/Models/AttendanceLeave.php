<?php

namespace App\Models;

use App\Models\Concerns\AuditsAttendance;
use App\Models\Concerns\BelongsToBranch;
use App\Services\Attendance\AttendanceAnalyzer;
use Illuminate\Database\Eloquent\Model;

/**
 * Time one person is excused: whole days, first to last inclusive, or part of one day
 * (from_time to to_time). Staff can ask for it themselves; it only counts once approved.
 */
class AttendanceLeave extends Model
{
    use AuditsAttendance, BelongsToBranch;

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
        return AttendanceLeaveType::options()[$this->type]->name ?? self::TYPES[$this->type] ?? 'Leave';
    }

    /**
     * Leave this person already has, approved or waiting, that the given days (and times, for
     * part of a day) would run into. Two part-day leaves on one day clash only if their times do.
     */
    public static function clash(int $staffId, string $from, string $to, ?string $fromTime = null, ?string $toTime = null): ?self
    {
        return static::query()->where('attendance_staff_id', $staffId)->whereIn('status', [self::APPROVED, self::PENDING])
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->orderBy('starts_on')->get()
            ->first(fn (self $other) => ! $fromTime || ! $toTime || ! $other->isPartDay() || ($fromTime < $other->to_time && $other->from_time < $toTime));
    }

    /** Asked for, added, approved, turned down, withdrawn or removed. */
    protected function auditEntry(string $verb, array $fields): ?array
    {
        $what = 'leave for '.($this->staff?->name ?? 'someone').' ('.$this->when().', '.$this->typeName().')';
        if ($verb === 'added') {
            return $this->status === self::PENDING ? ['leave_requested', 'Asked for '.$what] : ['leave_added', 'Added '.$what];
        }
        if ($verb === 'removed') {
            return $this->status === self::PENDING && (int) $this->requested_by === (int) auth()->id()
                ? ['leave_withdrawn', 'Withdrew the request for '.$what] : ['leave_removed', 'Removed '.$what];
        }
        if (in_array('status', $fields, true)) {
            return $this->status === self::APPROVED ? ['leave_approved', 'Approved '.$what] : ['leave_declined', 'Did not approve '.$what];
        }

        return ['leave_changed', 'Changed '.$what];
    }
}
