<?php

namespace App\Models\Concerns;

use App\Models\AuditTrail;
use Illuminate\Support\Str;

/**
 * Attendance records that note in the audit trail when someone adds, changes or removes them,
 * as "attendance.<thing>_added|changed|removed" with what changed. Only changes a signed-in person
 * makes are noted: punches from a terminal and the scheduler's work are not. Bulk work (a staff
 * list, a roster from Excel, a swap) notes one summary of its own and runs in AttendanceAudit::quietly().
 *
 * A model names itself with auditNoun() ('shift') and auditLabel() ('Morning'); it may leave
 * columns out with $auditIgnore, or decide the whole entry in auditEntry().
 */
trait AuditsAttendance
{
    public static function bootAuditsAttendance(): void
    {
        foreach (['created' => 'added', 'updated' => 'changed', 'deleted' => 'removed'] as $event => $verb) {
            static::$event(fn ($model) => $model->noteInAuditTrail($verb));
        }
    }

    protected function noteInAuditTrail(string $verb): void
    {
        if (\App\Support\AttendanceAudit::$quiet > 0 || ! auth()->check()) {
            return;
        }
        $skip = array_flip(['created_at', 'updated_at', 'clinic_id', 'branch_id', 'id', ...($this->auditIgnore ?? [])]);
        [$old, $new] = match ($verb) {
            'changed' => [array_intersect_key($this->getOriginal(), $changes = array_diff_key($this->getChanges(), $skip)), $changes],
            'added' => [[], array_diff_key($this->getAttributes(), $skip)],
            default => [array_diff_key($this->getAttributes(), $skip), []],
        };
        if ($verb === 'changed' && ! $new) {
            return;
        }
        $entry = $this->auditEntry($verb, array_keys($new));
        if ($entry) {
            AuditTrail::record('attendance.'.$entry[0], $entry[1], $this, $old, $new, null, true);
        }
    }

    /** [event, description], or null for no entry. $fields are the columns changed. */
    protected function auditEntry(string $verb, array $fields): ?array
    {
        $noun = $this->auditNoun();
        $what = 'the '.$noun.' '.$this->auditLabel();
        $changed = $verb === 'changed' && $fields ? ' ('.implode(', ', array_map(fn ($field) => $this->auditFieldName($field), $fields)).')' : '';

        return [Str::snake(str_replace(' ', '_', $noun)).'_'.$verb, ucfirst($verb === 'added' ? 'added' : ($verb === 'changed' ? 'changed' : 'removed')).' '.$what.$changed];
    }

    protected function auditNoun(): string
    {
        return Str::of(class_basename($this))->after('Attendance')->snake(' ')->toString();
    }

    protected function auditLabel(): string
    {
        return (string) ($this->name ?? '#'.$this->getKey());
    }

    protected function auditFieldName(string $field): string
    {
        return str_replace('_', ' ', preg_replace('/_(id|minutes|at)$/', '', $field));
    }
}
