<?php

namespace App\Models;

use App\Models\Concerns\AuditsAttendance;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * A department within a branch. Its heads see and run attendance for its people only. Working
 * hours, grace, break allowance and overtime threshold left empty follow the branch.
 */
class AttendanceDepartment extends Model
{
    use AuditsAttendance, BelongsToBranch;

    protected $fillable = ['name', 'heads', 'hours', 'grace_minutes', 'break_minutes', 'overtime_after_minutes'];

    protected $casts = ['heads' => 'array', 'hours' => 'array', 'grace_minutes' => 'integer', 'break_minutes' => 'integer', 'overtime_after_minutes' => 'integer'];

    public function staff()
    {
        return $this->hasMany(AttendanceStaff::class);
    }

    public function headedBy(int $userId): bool
    {
        return in_array($userId, array_map('intval', $this->heads ?? []), true);
    }

    public function hasOwnRules(): bool
    {
        return $this->hours || $this->grace_minutes !== null || $this->break_minutes !== null || $this->overtime_after_minutes !== null;
    }
}
