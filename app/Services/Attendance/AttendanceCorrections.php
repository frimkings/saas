<?php

namespace App\Services\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceStaff;
use App\Models\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A manager's corrections to punches: add one that was missed, change a time, remove one made
 * by mistake. The terminal's own record is never lost: removing voids the punch, changing voids
 * it and adds the corrected one beside it, and each step is written to the audit trail whatever
 * the clinic's plan, because hours and pay are worked out from these.
 */
class AttendanceCorrections
{
    /** $at is the branch's clock time, as punches are stored. */
    public function add(AttendanceStaff $staff, CarbonImmutable $at, string $reason, CarbonImmutable $now, ?AttendancePunch $replaces = null): AttendancePunch
    {
        $reason = $this->reason($reason);
        $at = $at->setSecond(0);
        if ($at->gt($now)) {
            throw ValidationException::withMessages(['punchTime' => 'A punch cannot be in the future.']);
        }

        return DB::transaction(function () use ($staff, $at, $reason, $replaces) {
            $existing = AttendancePunch::withVoided()->where('attendance_staff_id', $staff->id)->where('punched_at', $at->format('Y-m-d H:i:s'))->lockForUpdate()->first();
            if ($existing && ! $existing->voided_at) {
                throw ValidationException::withMessages(['punchTime' => 'There is already a punch at '.$at->format('g:i A').'.']);
            }
            // A punch removed earlier at this very time is brought back, not duplicated.
            $punch = $existing ?? new AttendancePunch(['attendance_staff_id' => $staff->id, 'punched_at' => $at->format('Y-m-d H:i:s'), 'source' => 'manual']);
            $punch->fill(['created_by' => auth()->id(), 'reason' => $reason, 'replaces_id' => $replaces?->id,
                'voided_at' => null, 'voided_by' => null, 'void_reason' => null])->save();
            if (! $replaces) {
                AuditTrail::record('attendance.punch_added', "Added a punch for {$staff->name} at ".$at->format('j M Y, g:i A').": {$reason}",
                    $punch, [], ['punched_at' => $at->format('Y-m-d H:i'), 'reason' => $reason], null, true);
            }

            return $punch;
        });
    }

    public function void(AttendancePunch $punch, string $reason, bool $audit = true): void
    {
        $reason = $this->reason($reason);
        $punch->forceFill(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason])->save();
        if ($audit) {
            AuditTrail::record('attendance.punch_removed', 'Removed the '.$punch->punched_at->format('j M Y, g:i A')." punch of {$punch->staff?->name}: {$reason}",
                $punch, ['punched_at' => $punch->punched_at->format('Y-m-d H:i'), 'source' => $punch->source], ['reason' => $reason], null, true);
        }
    }

    /** Change a punch's time: the original is voided and the corrected punch points back at it. */
    public function move(AttendancePunch $punch, CarbonImmutable $to, string $reason, CarbonImmutable $now): AttendancePunch
    {
        $reason = $this->reason($reason);
        if ($to->setSecond(0)->equalTo($punch->punched_at->toImmutable()->setSecond(0))) {
            throw ValidationException::withMessages(['punchTime' => 'That is the time the punch already has.']);
        }

        return DB::transaction(function () use ($punch, $to, $reason, $now) {
            $this->void($punch, $reason, false);
            $new = $this->add($punch->staff, $to, $reason, $now, $punch);
            AuditTrail::record('attendance.punch_changed', "Changed a punch of {$punch->staff->name} from ".$punch->punched_at->format('j M Y, g:i A').' to '.$to->format('g:i A').": {$reason}",
                $new, ['punched_at' => $punch->punched_at->format('Y-m-d H:i')], ['punched_at' => $to->format('Y-m-d H:i'), 'reason' => $reason], null, true);

            return $new;
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['punchReason' => 'Say why, in at least 5 characters.']);
        }

        return $reason;
    }
}
