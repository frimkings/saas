<?php

namespace App\Support\Access;

use App\Support\OpticalAccess;

/**
 * What each role can do, in plain words, for the read-only role pages: the clinic's "What each
 * role can do" and the platform's Roles page. Built from the role map, so it can't drift from
 * what the app actually allows.
 */
final class RoleGuide
{
    /** Every role, in the order people think of them. */
    public const ROLES = [RoleMap::OWNER, 'Manager', 'Secretary', 'Cashier', 'Doctor', 'Optician', 'Optical Assistant', 'Lab Technician', 'Staff'];

    private const ABOUT = [
        RoleMap::OWNER => 'Everything, always: money, approvals, settings, staff and the subscription. Can\'t be switched off anything.',
        'Manager' => 'Runs the clinic day to day: front desk, till, stock, expenses, approvals, staff and attendance. Not the owner-only money pages (sales reports, income statement), clinic settings or the subscription.',
        'Secretary' => 'The front desk: patients, appointments, clearance, spectacles, the till and every receipt. On optical plans, the optical sales desk too.',
        'Cashier' => 'The till: sales, balances, refund log, day close, and refunds on their own sales. On optical plans, the optical sales desk too.',
        'Doctor' => 'The doctor\'s queue, consultations, refraction, patient records, referrals and clinical printouts.',
        'Staff' => 'Clock in and out and see their own attendance. Nothing else.',
    ];

    public static function about(string $role): string
    {
        return self::ABOUT[$role] ?? (OpticalAccess::ROLES[$role]['about'] ?? '');
    }

    /** True for roles whose screens are the optical ones (set by OpticalAccess, not the catalogue). */
    public static function isOpticalRole(string $role): bool
    {
        return isset(OpticalAccess::ROLES[$role]);
    }

    /** @return array<string, array<string, array{label: string, roles: array<string, bool>}>> group => key => row */
    public static function matrix(): array
    {
        $groups = [];
        foreach (Permissions::keys() as $key) {
            $roles = [];
            foreach (self::ROLES as $role) {
                $roles[$role] = RoleMap::grants([$role], $key);
            }
            $groups[Permissions::group($key)][$key] = ['label' => Permissions::label($key), 'roles' => $roles];
        }

        // The optical screens check their own permissions (OpticalAccess): show them too, or the
        // optical roles would look as if they could do nothing.
        foreach (self::OPTICAL as $permission => $label) {
            $roles = [];
            foreach (self::ROLES as $role) {
                $roles[$role] = self::opticalGrants($role, $permission);
            }
            $groups['Optical'][$permission] = ['label' => $label, 'roles' => $roles];
        }

        return $groups;
    }

    private const OPTICAL = [
        OpticalAccess::SALES => 'Optical sales desk: orders, POS, collections, prescriptions, partner clinics',
        OpticalAccess::LAB => 'Optical lab: workbench, job tracking, lens catalogue',
        OpticalAccess::STOCK => 'Optical stock and stock counts',
        OpticalAccess::REPORTS => 'Optical reports',
    ];

    /** Mirrors OpticalAccess::installRoles: managers and the Owner have every optical screen. */
    private static function opticalGrants(string $role, string $permission): bool
    {
        if (in_array($role, [RoleMap::OWNER, 'Manager'], true)) return true;
        if (in_array($role, OpticalAccess::DESK_ROLES, true) && $permission === OpticalAccess::SALES) return true;

        return in_array($permission, OpticalAccess::ROLES[$role]['permissions'] ?? [], true);
    }

    /** @return array<string, string> role => what clinics call it */
    public static function labels(): array
    {
        return collect(self::ROLES)->mapWithKeys(fn ($role) => [$role => RoleNames::label($role)])->all();
    }
}
