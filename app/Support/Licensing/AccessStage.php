<?php

namespace App\Support\Licensing;

use Carbon\CarbonInterface;

/**
 * Where a clinic stands relative to its subscription (hosted) or license (offline) expiry.
 *
 *   active       ─ more than `expiry_warning_days` left
 *   expiring     ─ within the warning window: every user sees a renewal banner
 *   grace        ─ expired, but everything still works for `grace_days`
 *   payment_due  ─ staff see "payment has not been made"; admins only reach the renewal page
 *   locked       ─ staff see "contact support"; admins can still reach the renewal page
 *
 * Paying (or activating a new license) moves the clinic straight back to active.
 */
final class AccessStage
{
    public const ACTIVE = 'active';
    public const EXPIRING = 'expiring';
    public const GRACE = 'grace';
    public const PAYMENT_DUE = 'payment_due';
    public const LOCKED = 'locked';

    public function __construct(
        public readonly string $stage,
        public readonly ?CarbonInterface $expiresAt,
        public readonly ?CarbonInterface $lockedAt,
        public readonly bool $hosted,
    ) {
    }

    public static function forExpiry(?CarbonInterface $expiresAt, bool $hosted, ?CarbonInterface $now = null): self
    {
        $now ??= now();

        if (!$expiresAt) {
            return new self(self::ACTIVE, null, null, $hosted);
        }

        $graceEnds = $expiresAt->copy()->addDays(config('subscriptions.grace_days', 1));
        $lockedAt  = $graceEnds->copy()->addDays(config('subscriptions.payment_due_days', 1));

        $stage = match (true) {
            $now->lt($expiresAt->copy()->subDays(config('subscriptions.expiry_warning_days', 7))) => self::ACTIVE,
            $now->lt($expiresAt) => self::EXPIRING,
            $now->lt($graceEnds) => self::GRACE,
            $now->lt($lockedAt)  => self::PAYMENT_DUE,
            default              => self::LOCKED,
        };

        return new self($stage, $expiresAt, $lockedAt, $hosted);
    }

    /** A platform-suspended or cancelled clinic is locked whatever its dates say. */
    public static function locked(?CarbonInterface $expiresAt, bool $hosted): self
    {
        return new self(self::LOCKED, $expiresAt, $expiresAt, $hosted);
    }

    /** Staff are shut out and only admins may use the renewal page. */
    public function blocksStaff(): bool
    {
        return in_array($this->stage, [self::PAYMENT_DUE, self::LOCKED], true);
    }

    public function inGrace(): bool
    {
        return $this->stage === self::GRACE;
    }

    public function showsBanner(): bool
    {
        return $this->stage !== self::ACTIVE;
    }

    public function daysLeft(): ?int
    {
        return $this->expiresAt ? max(0, (int) ceil(now()->diffInSeconds($this->expiresAt, false) / 86400)) : null;
    }

    /** Where a clinic admin renews: the subscription portal (hosted) or the license page (offline). */
    public function renewalRoute(): string
    {
        return $this->hosted ? 'admin.subscription' : 'admin.license';
    }
}
