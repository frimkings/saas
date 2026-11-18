<?php

namespace App\Support\Access;

/**
 * What each role can do, built by the Nexus team and the same for every clinic. Phase 1 copies
 * exactly the access the old role rules gave (tests/Fixtures/route-access-before.json), so nobody
 * gains or loses anything. Optical roles are handled by OpticalAccess and carry nothing here.
 */
final class RoleMap
{
    public const OWNER = 'Super Admin';

    /** Front desk, cashier and optician all work the till. */
    private const TILL = ['pos.sell', 'sales.view', 'balances.view', 'refunds.view'];

    private const FRONT_DESK = [
        'patients.view', 'appointments.manage', 'clearance.manage', 'spectacles.manage',
        'optical_links.respond', 'scans.desk',
    ];

    private const CLINICAL = ['clinical.queue', 'clinical.records', 'clinical.referrals', 'clinical.print'];

    private const MANAGEMENT = [
        'optical_centres.manage', 'scans.export', 'clinical.task_center', 'diagnoses.manage',
        'sales.manage', 'dayclose.manage', 'cashbook.view', 'expenses.manage', 'quotations.manage',
        'ledger.view', 'insurance.manage',
        'approve.discounts', 'approve.refunds', 'approve.revokes',
        'inventory.manage', 'inventory.movements', 'purchasing.manage', 'lens_report.view',
        'reports.sales', 'reports.finance', 'messages.recall', 'messages.logs',
        'attendance.manage', 'audit.view', 'branches.manage', 'settings.manage', 'optical.manage',
    ];

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        return match ($role) {
            self::OWNER => Permissions::keys(),
            'Manager' => [...self::TILL, ...self::FRONT_DESK, ...self::MANAGEMENT],
            'Secretary' => [...self::TILL, ...self::FRONT_DESK],
            'Cashier' => [...self::TILL, 'scans.desk', 'dayclose.manage'],
            'Optician' => self::TILL,
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
