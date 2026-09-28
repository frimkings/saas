<?php

namespace App\Services\Messaging;

use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Services\OwnerAlerts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Each branch's share of the clinic's SMS. The clinic keeps one wallet; a branch the owner has
 * given a limit stops sending once it has used it, whatever the message, until the owner adds
 * more. The count never resets on its own. A branch with no limit sends freely (its use is
 * still counted, so the owner can see it). The owner is emailed at 80% and when it runs out.
 */
class BranchSmsLimits
{
    public const WARN_AT = 0.8;

    /** Clinic-wide messages sent by the scheduler or a broadcast, charged to the patient's home branch. */
    private const HOME_BRANCH_MESSAGES = ['birthday_wishes', 'patient_recall', 'custom_broadcast'];

    /** Which branch pays: the patient's home branch for clinic-wide messages, otherwise where it happened. */
    public function branchFor(?int $patientId, ?string $templateKey): ?int
    {
        $context = app(TenantContext::class);
        if ($patientId && in_array($templateKey, self::HOME_BRANCH_MESSAGES, true)) {
            $home = Patient::whereKey($patientId)->value('home_branch_id');
            if ($home && Branch::whereKey($home)->where('clinic_id', $context->clinicId() ?? SmsLog::clinicIdForWrite())->where('is_active', true)->exists()) {
                return (int) $home;
            }
        }

        return $context->branchId() ?? Branch::where('clinic_id', SmsLog::clinicIdForWrite())->orderByDesc('is_default')->orderBy('id')->value('id');
    }

    /**
     * Count $parts against the branch, under a lock so two sends can't both take the last one.
     * Call inside the transaction that creates the SMS. Returns what the owner should hear about.
     *
     * @return array{branch: Branch, events: list<'low'|'out'>}|null
     * @throws BranchSmsLimitReachedException
     */
    public function take(?int $branchId, int $parts): ?array
    {
        $branch = $branchId ? Branch::lockForUpdate()->find($branchId) : null;
        if (! $branch) return null;

        if ($branch->sms_limit !== null && $branch->sms_used + $parts > $branch->sms_limit) {
            throw new BranchSmsLimitReachedException($branch->name, max(0, $branch->sms_limit - $branch->sms_used), $parts);
        }

        $branch->sms_used += $parts;
        $events = [];
        if ($branch->sms_limit !== null) {
            if ($branch->sms_used >= $branch->sms_limit && ! $branch->sms_out_at) {
                $branch->sms_out_at = now();
                $events[] = 'out';
            } elseif ($branch->sms_used >= (int) ceil($branch->sms_limit * self::WARN_AT) && ! $branch->sms_warned_at) {
                $branch->sms_warned_at = now();
                $events[] = 'low';
            }
        }
        $branch->save();

        return ['branch' => $branch, 'events' => $events];
    }

    /** Tell the owner, once the sending transaction has committed. */
    public function announce(?array $taken): void
    {
        foreach ($taken['events'] ?? [] as $event) {
            $event === 'out'
                ? app(OwnerAlerts::class)->branchSmsOut($taken['branch'])
                : app(OwnerAlerts::class)->branchSmsLow($taken['branch']);
        }
    }

    /** Give back what an undelivered SMS counted against its branch. Safe to call more than once. */
    public function giveBack(SmsLog $log): void
    {
        if (! $log->branch_counted) return;

        DB::transaction(function () use ($log) {
            $log = SmsLog::withoutGlobalScopes()->lockForUpdate()->find($log->id);
            if (! $log || ! $log->branch_counted) return;
            if ($branch = Branch::lockForUpdate()->find($log->branch_id)) {
                $branch->sms_used = max(0, $branch->sms_used - $log->branch_counted);
                $this->clearWarnings($branch);
                $branch->save();
            }
            $log->forceFill(['branch_counted' => 0])->save();
        });
    }

    /** Add SMS to a branch's limit (a branch with no limit starts from what it has used). */
    public function add(Branch $branch, int $parts): Branch
    {
        if ($parts < 1) throw ValidationException::withMessages(['add' => 'Enter how many SMS to add, 1 or more.']);

        return $this->change($branch, fn (Branch $locked) => ($locked->sms_limit ?? $locked->sms_used) + $parts, "Added {$parts} SMS");
    }

    /** Set the limit exactly; null removes it. It can't be set below what the branch has already used. */
    public function set(Branch $branch, ?int $limit): Branch
    {
        return $this->change($branch, function (Branch $locked) use ($limit) {
            if ($limit !== null && $limit < $locked->sms_used) {
                throw ValidationException::withMessages(['limit' => "{$locked->name} has already used {$locked->sms_used} SMS; the limit can't be lower than that."]);
            }
            return $limit;
        }, $limit === null ? 'Removed the SMS limit' : "Set the SMS limit to {$limit}");
    }

    private function change(Branch $branch, callable $newLimit, string $what): Branch
    {
        return DB::transaction(function () use ($branch, $newLimit, $what) {
            $locked = Branch::lockForUpdate()->findOrFail($branch->id);
            $old = $locked->sms_limit;
            $locked->sms_limit = $newLimit($locked);
            $this->clearWarnings($locked);
            $locked->save();
            AuditTrail::record('branch.sms_limit_changed', "{$what} for {$locked->name}", $locked,
                ['sms_limit' => $old, 'sms_used' => $locked->sms_used], ['sms_limit' => $locked->sms_limit], null, true);

            return $locked;
        });
    }

    /** Warnings go again once the branch is back below the level that triggered them. */
    private function clearWarnings(Branch $branch): void
    {
        if ($branch->sms_limit === null || $branch->sms_used < $branch->sms_limit) $branch->sms_out_at = null;
        if ($branch->sms_limit === null || $branch->sms_used < (int) ceil($branch->sms_limit * self::WARN_AT)) $branch->sms_warned_at = null;
    }
}
