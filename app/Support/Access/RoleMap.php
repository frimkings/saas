<?php

namespace App\Support\Access;

/**
 * What each role can do, built by the Nexus team and the same for every clinic. Phase 1 copies
 * exactly the access the old role rules gave (tests/Fixtures/route-access-before.json), so nobody
 * gains or loses anything. Decided since (2026-11-18): Managers don't get sales reports, the
 * income / combined statement or clinic settings; Opticians are bench-only (no till).
 * Optical roles are handled by OpticalAccess and carry nothing here.
 */
final class RoleMap
{
    public const OWNER = 'Super Admin';

    /** Front desk and cashier work the till. */
    private const TILL = ['pos.sell', 'sales.view', 'balances.view', 'refunds.view'];

    private const FRONT_DESK = [
        'patients.view', 'appointments.manage', 'clearance.manage', 'spectacles.manage',
        'scans.desk',
    ];

    private const CLINICAL = ['clinical.queue', 'clinical.records', 'clinical.referrals', 'clinical.print'];

    private const MANAGEMENT = [
        'optical_centres.manage', 'scans.export', 'clinical.task_center', 'diagnoses.manage',
        'sales.manage', 'dayclose.manage', 'cashbook.view', 'expenses.manage', 'quotations.manage',
        'ledger.view', 'insurance.manage', 'insurance.payments',
        'approve.discounts', 'approve.refunds', 'approve.revokes',
        'inventory.manage', 'inventory.movements', 'purchasing.manage', 'lens_report.view',
        'dashboard.view', 'messages.recall', 'messages.logs',
        'sales.refund_any', 'pos.all_open_sales', 'receipts.view_all', 'scans.manage', 'spectacles.override',
        'attendance.manage', 'audit.view', 'branches.manage', 'optical.manage',
        // Held through old database permissions until phase 5; now part of the role itself.
        'staff.manage', 'roles.view',
    ];

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        return match ($role) {
            self::OWNER => Permissions::keys(),
            'Manager' => [...self::TILL, ...self::FRONT_DESK, ...self::MANAGEMENT],
            'Secretary' => [...self::TILL, ...self::FRONT_DESK, 'receipts.view_all'],
            'Cashier' => [...self::TILL, 'scans.desk', 'dayclose.manage', 'sales.refund_own'],
            'Doctor' => self::CLINICAL,
            default => [],
        };
    }

    /** @param iterable<string> $roles */
    public static function grants(iterable $roles, string $permission): bool
    {
        foreach ($roles as $role) {
            if (in_array($permission, self::permissionsFor($role), true)) {
                return true;
            }
        }

        return false;
    }
}
