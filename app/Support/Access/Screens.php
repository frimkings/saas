<?php

namespace App\Support\Access;

/**
 * Route name => the permissions, any one of which opens it. Every route behind the `access`
 * middleware must be listed (tests/Feature/AccessScreensTest.php); an unlisted one is refused.
 * Plan features are still checked by their own `feature:` middleware.
 */
final class Screens
{
    public const MAP = [
        // Patients & front desk
        'secretary.dashboard' => ['patients.view'],
        'secretary.patients' => ['patients.view'],
        'secretary.appointments' => ['appointments.manage'],
        'secretary.patient-clearance' => ['clearance.manage'],
        'secretary.spectacles' => ['spectacles.manage'],
        'secretary.optical-sent' => ['spectacles.manage'],
        'optical-links.accept' => ['optical_links.respond'],
        'secretary.optical-centres' => ['optical_centres.manage'],
        'secretary.optical-owed' => ['optical_centres.manage'],
        'frontdesk.scans' => ['scans.desk'],
        'frontdesk.scans.report' => ['scans.export'],

        // Clinical
        'doctor.dashboard' => ['clinical.queue'],
        'doctor.patient-awaiting' => ['clinical.queue'],
        'doctor.all-records' => ['clinical.records'],
        'doctor.patient-records' => ['clinical.records'],
        'doctor.patient-timeline' => ['clinical.records'],
        'doctor.medical-record.preview' => ['clinical.records'],
        'doctor.referrals' => ['clinical.referrals'],
        'doctor.referral.pdf' => ['clinical.referrals'],
        'doctor.consultation.pdf' => ['clinical.print'],
        'doctor.prescription.print' => ['clinical.print'],
        'doctor.visit-summary.print' => ['clinical.print'],
        'doctor.medical-record.pdf' => ['clinical.print'],
        'doctor.visit-summaries.download' => ['clinical.print'],
        'refraction.print' => ['clinical.print'],
        'admin.clinical-task-center' => ['clinical.task_center'],
        'admin.diagnoses' => ['diagnoses.manage'],

        // Sales & money
        'cart' => ['pos.sell'],
        'cashier.dashboard' => ['pos.sell'],
        'cashier.seller-desk' => ['pos.sell'],
        'cashier.ajax-patients' => ['pos.sell'],
        'cashier.clearance-receipt' => ['pos.sell'],
        'cashier.receipt.show' => ['pos.sell'],
        'cashier.receipt.pdf' => ['pos.sell'],
        'cashier.visit-receipt.sale' => ['pos.sell'],
        'cashier.visit-receipt.show' => ['pos.sell'],
        'cashier.sales-records' => ['sales.view'],
        'admin.sales-records' => ['sales.manage'],
        'admin.sales-records.previous' => ['sales.manage'],
        'cashier.outstanding-balances' => ['balances.view'],
        'refunds.logs' => ['refunds.view'],
        'refunds.receipt' => ['refunds.view'],
        'refunds.receipt.pdf' => ['refunds.view'],
        'admin.day-close' => ['dayclose.manage'],
        'admin.cash-book' => ['cashbook.view'],
        'admin.daily-cash-summary' => ['cashbook.view'],
        'admin.expenses' => ['expenses.manage'],
        'admin.expenses.previous' => ['expenses.manage'],
        'admin.expenses.receipt' => ['expenses.manage'],
        'admin.quotations' => ['quotations.manage'],
        'admin.quotations.pdf' => ['quotations.manage'],
        'admin.patient-ledger' => ['ledger.view'],
        'admin.insurance.claims' => ['insurance.manage'],
        'admin.insurance.insurers' => ['insurance.manage'],
        'admin.insurance.receivables' => ['insurance.manage'],
        'admin.insurance.payments' => ['insurance.payments'],

        // Approvals
        'admin.approvals' => ['approve.discounts', 'approve.refunds', 'approve.revokes'],
        'admin.discount-approvals' => ['approve.discounts'],
        'discount-approval-notices.approve' => ['approve.discounts'],
        'discount-approval-notices.reject' => ['approve.discounts'],
        'admin.refund-approvals' => ['approve.refunds'],
        'admin.clearance-revoke-approvals' => ['approve.revokes'],
        'admin.password-reset-approvals' => ['approve.password_resets'],

        // Stock & purchasing
        'admin.product' => ['inventory.manage'],
        'admin.category' => ['inventory.manage'],
        'admin.legacy-medication-names' => ['inventory.manage'],
        'admin.stock-movements' => ['inventory.movements'],
        'admin.inventory-alerts' => ['inventory.movements'],
        'admin.purchase-orders' => ['purchasing.manage'],
        'admin.purchase-orders.pdf' => ['purchasing.manage'],
        'admin.stock-transfers' => ['purchasing.manage'],
        'admin.suppliers' => ['purchasing.manage'],
        'admin.lens-outstanding-report' => ['lens_report.view'],

        // Reports
        'admin.dashboard' => ['reports.sales'],
        'admin.reports' => ['reports.sales'],
        'reports.export.pdf' => ['reports.sales'],
        'admin.income-statement' => ['reports.finance'],
        'admin.income-statement.preview' => ['reports.finance'],
        'admin.income-statement.export.csv' => ['reports.finance'],
        'admin.income-statement.export.pdf' => ['reports.finance'],
        'admin.combined-statement' => ['reports.finance'],
        'admin.combined-statement.export' => ['reports.finance'],
        'admin.report-delivery' => ['reports.delivery'],

        // Communications
        'admin.messages' => ['messages.patients'],
        'admin.sms-settings' => ['messages.patients'],
        'admin.whatsapp-settings' => ['messages.patients'],
        'admin.broadcast' => ['messages.broadcast'],
        'admin.patient-recall' => ['messages.recall'],
        'admin.sms-logs' => ['messages.logs'],

        // Staff, settings, billing
        'admin.users' => ['staff.manage'],
        'admin.roles-permissions' => ['roles.view'],
        'admin.attendance.departments' => ['attendance.manage'],
        'admin.audit-trail' => ['audit.view'],
        'admin.login-history' => ['audit.view'],
        'admin.branches' => ['branches.manage'],
        'admin.settings' => ['settings.manage'],
        'admin.subscription' => ['billing.manage'],
        'admin.subscription.invoice' => ['billing.manage'],
        'admin.subscription.receipt' => ['billing.manage'],
        'admin.license' => ['billing.manage'],
        'admin.backups' => ['system.manage'],
        'admin.backup.download' => ['system.manage'],
        'admin.mail-settings' => ['system.manage'],
        'admin.offline-health' => ['system.manage'],

        // Optical screens that are manager-only (OpticalAccess checks the rest)
        'optical.purchasing' => ['optical.manage'],
        'optical.purchasing.print' => ['optical.manage'],
        'optical.stock.receive-lenses' => ['optical.manage'],
        'optical.expenses' => ['optical.manage'],
        'optical.expenses.receipt' => ['optical.manage'],
        'optical.profit' => ['optical.manage'],
        'optical.profit.export' => ['optical.manage'],
        'optical.settings' => ['optical.manage'],
        'optical.audit-trail' => ['optical.manage'],
        'optical.login-history' => ['optical.manage'],
    ];

    /** @return list<string>|null null when the route is not listed */
    public static function permissionsFor(?string $routeName): ?array
    {
        return $routeName !== null ? (self::MAP[$routeName] ?? null) : null;
    }
}
