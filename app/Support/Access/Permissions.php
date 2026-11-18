<?php

namespace App\Support\Access;

/**
 * Every permission in the clinic app, in one place. Roles are built by the Nexus team
 * (RoleMap); clinics only give people roles and, later, switch single permissions on or off
 * per person. Plan features stay a separate gate: a permission never unlocks a feature the
 * clinic's plan does not include. See docs/roles-and-permissions.md.
 */
final class Permissions
{
    /**
     * key => [label, group, legacy permission names that also grant it].
     * The legacy names are the old Spatie permissions still held in the database; they keep
     * today's access working (a clinic's own edits included) until roles are reset.
     */
    public const ALL = [
        // Patients & front desk
        'patients.view' => ['Patients and the front desk dashboard', 'Patients & front desk', []],
        'appointments.manage' => ['Appointments', 'Patients & front desk', []],
        'clearance.manage' => ['Patient clearance (send to doctor)', 'Patients & front desk', []],
        'spectacles.manage' => ['Spectacles and orders sent to optical', 'Patients & front desk', []],
        'optical_centres.manage' => ['Optical centres: links, invitations and money owed', 'Patients & front desk', []],
        'scans.desk' => ['Diagnostic scans: front desk', 'Patients & front desk', []],
        'scans.export' => ['Diagnostic scans: export report', 'Patients & front desk', []],

        // Clinical
        'clinical.queue' => ["Doctor's dashboard and queue", 'Clinical', []],
        'clinical.records' => ['Consultations, refraction and patient records', 'Clinical', []],
        'clinical.referrals' => ['Referrals', 'Clinical', []],
        'clinical.print' => ['Clinical PDFs, prescriptions and visit cards', 'Clinical', []],
        'clinical.task_center' => ['Clinical task centre', 'Clinical', []],
        'diagnoses.manage' => ['Diagnosis list', 'Clinical', []],

        // Sales & money
        'pos.sell' => ['Point of sale, cart and receipts', 'Sales & money', []],
        'sales.view' => ['Sales records (desk)', 'Sales & money', []],
        'sales.manage' => ['Sales records (all, including previous software)', 'Sales & money', []],
        'balances.view' => ['Outstanding balances', 'Sales & money', []],
        'refunds.view' => ['Refund log and refund receipts', 'Sales & money', []],
        'dayclose.manage' => ['Day close', 'Sales & money', []],
        'cashbook.view' => ['Cash book and daily cash summary', 'Sales & money', []],
        'expenses.manage' => ['Expenses', 'Sales & money', []],
        'quotations.manage' => ['Quotations', 'Sales & money', []],
        'ledger.view' => ['Patient ledger', 'Sales & money', []],
        'insurance.manage' => ['Insurers, claims and receivables', 'Sales & money', []],
        'insurance.payments' => ['Record insurer payments', 'Sales & money', ['record insurer payments']],

        // Approvals (maker–checker comes in phase 3)
        'approve.discounts' => ['Approve discounts', 'Approvals', ['manage billing']],
        'approve.refunds' => ['Approve refunds', 'Approvals', ['manage billing']],
        'approve.revokes' => ['Approve clearance revokes', 'Approvals', ['manage billing', 'approve clearance revoke']],
        'approve.password_resets' => ['Approve password resets', 'Approvals', []],

        // Stock & purchasing
        'inventory.manage' => ['Products and categories', 'Stock & purchasing', []],
        'inventory.movements' => ['Stock movements and alerts', 'Stock & purchasing', []],
        'purchasing.manage' => ['Suppliers, purchase orders and transfers', 'Stock & purchasing', []],
        'lens_report.view' => ['Lens outstanding report', 'Stock & purchasing', []],

        // Reports
        'dashboard.view' => ['Admin dashboard (business overview)', 'Reports', []],
        'reports.sales' => ['Sales reports', 'Reports', ['manage billing']],
        'reports.finance' => ['Income statement and combined statement', 'Reports', ['manage billing']],
        'reports.delivery' => ['Scheduled report emails', 'Reports', []],

        // Communications
        'messages.patients' => ['Patient SMS and WhatsApp settings', 'Communications', []],
        'messages.broadcast' => ['Broadcast SMS', 'Communications', []],
        'messages.recall' => ['Patient recall', 'Communications', []],
        'messages.logs' => ['SMS logs', 'Communications', []],

        // Staff, settings, billing
        'staff.manage' => ['Staff and their roles', 'Staff & settings', ['manage users']],
        'roles.view' => ['See what each role can do', 'Staff & settings', []],
        'attendance.manage' => ['Attendance departments', 'Staff & settings', []],
        'audit.view' => ['Audit trail and login history', 'Staff & settings', []],
        'branches.manage' => ['Branches', 'Staff & settings', []],
        'settings.manage' => ['Clinic settings', 'Staff & settings', []],
        'billing.manage' => ['Subscription, licence and invoices', 'Staff & settings', []],
        'system.manage' => ['Backups, mail settings and offline health', 'Staff & settings', []],

        // Optical (the optical screens themselves are checked by OpticalAccess)
        'optical.manage' => ['Optical: purchasing, expenses, profit, settings and audit', 'Optical', []],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    public static function label(string $key): string
    {
        return self::ALL[$key][0] ?? $key;
    }

    public static function group(string $key): string
    {
        return self::ALL[$key][1] ?? 'Other';
    }

    /** @return list<string> */
    public static function legacyNames(string $key): array
    {
        return self::ALL[$key][2] ?? [];
    }
}
