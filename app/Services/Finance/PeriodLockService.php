<?php

namespace App\Services\Finance;

use App\Models\AuditTrail;
use App\Models\IncomeStatementPeriodLock;
use Carbon\CarbonInterface;

/**
 * Closing a reporting period, per business line. The clinic and optical statements
 * each lock their own periods; a lock on one never affects the other.
 */
class PeriodLockService
{
    /** The lock for exactly this period, if it has been closed. */
    public function find(string $line, CarbonInterface $from, CarbonInterface $to): ?IncomeStatementPeriodLock
    {
        return IncomeStatementPeriodLock::with('lockedBy')->businessLine($line)
            ->whereDateIndexed('from_date', $from->toDateString())
            ->whereDateIndexed('to_date', $to->toDateString())
            ->first();
    }

    /** Any closed period that contains this date. */
    public function covering(string $line, CarbonInterface|string $date): ?IncomeStatementPeriodLock
    {
        $day = is_string($date) ? $date : $date->toDateString();

        return IncomeStatementPeriodLock::businessLine($line)
            ->whereDateIndexed('from_date', '<=', $day)
            ->whereDateIndexed('to_date', '>=', $day)
            ->orderBy('from_date')
            ->first();
    }

    /** @param array<string, float>|null $snapshot the statement totals at the moment of locking */
    public function lock(string $line, CarbonInterface $from, CarbonInterface $to, ?string $notes = null, ?array $snapshot = null): IncomeStatementPeriodLock
    {
        $lock = IncomeStatementPeriodLock::firstOrCreate(
            ['business_line' => $line, 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString()],
            ['locked_by' => auth()->id(), 'locked_at' => now(), 'notes' => $notes ?: null, 'snapshot' => $snapshot]
        );
        if ($lock->wasRecentlyCreated) {
            AuditTrail::record('report.period_locked', ucfirst($line)." statement locked for {$from->toDateString()} to {$to->toDateString()}", $lock);
        }

        return $lock;
    }

    public function unlock(string $line, CarbonInterface $from, CarbonInterface $to): void
    {
        $lock = $this->find($line, $from, $to);
        if (! $lock) return;
        AuditTrail::record('report.period_unlocked', ucfirst($line)." statement unlocked for {$from->toDateString()} to {$to->toDateString()}", $lock, $lock->only(['locked_by', 'locked_at', 'notes']), []);
        $lock->delete();
    }
}
