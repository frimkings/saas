<?php

return [
    'approval_expiry_days' => (int) env('SUBSCRIPTION_APPROVAL_EXPIRY_DAYS', 7),
    'offboarding_retention_days' => (int) env('SUBSCRIPTION_OFFBOARDING_RETENTION_DAYS', 30),
    // Expiry flow (hosted subscriptions and offline licenses), in whole days after the expiry moment:
    // grace: everything still works; payment due: staff see "payment not made" and admins only the
    // renewal page; after that: staff see "contact support" while admins can still renew.
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 1),
    'payment_due_days' => (int) env('SUBSCRIPTION_PAYMENT_DUE_DAYS', 1),
    // Every staff member sees a renewal banner from this many days before expiry.
    'expiry_warning_days' => (int) env('SUBSCRIPTION_EXPIRY_WARNING_DAYS', 7),
    // A restricted clinic remains readable for this many days before suspension.
    'restriction_days' => (int) env('SUBSCRIPTION_RESTRICTION_DAYS', 7),

    // Clinical safety features remain available in read-only/restricted mode.
    'restricted_features' => [
        'patient_records',
        'consultations',
        'reports',
    ],

    // Server-side route prefixes close direct-URL gaps even if navigation is hidden.
    'route_features' => [
        'doctor' => 'clinical',
        'secretary' => 'clinical',
        'cashier' => 'clinical',
        'cart' => 'clinical',
        'admin.clinical-task-center' => 'clinical',
        'admin.diagnoses' => 'clinical',
        'admin.patient-ledger' => 'clinical',
        'admin.patient-recall' => 'clinical',
        // Clinic-side stock, pricing and billing (the optical module has its own).
        'admin.category' => 'clinical',
        'admin.product' => 'clinical',
        'admin.insurance' => 'clinical',
        'admin.purchase-orders' => 'clinical',
        'admin.quotations' => 'clinical',
        'admin.sales-records' => 'clinical',
        'admin.daily-cash-summary' => 'clinical',
        'admin.discount-approvals' => 'clinical',
        'admin.clearance-revoke-approvals' => 'clinical',
        'admin.inventory-alerts' => 'inventory',
        'admin.stock-movements' => 'inventory',
        'admin.stock-transfers' => 'inventory',
        'admin.suppliers' => 'inventory',
        'admin.income-statement' => 'advanced_reports',
        'admin.lens-outstanding-report' => 'spectacles_pro',
        'admin.audit-trail' => 'audit_trail',
        'admin.login-history' => 'audit_trail',
        'admin.sms-logs' => 'sms_campaigns',
        'admin.expenses' => 'expense_tracking',
        'admin.report-delivery' => 'report_delivery',
        'cashier.outstanding-balances' => 'outstanding_balances',
        'doctor.referrals' => 'referrals',
        'secretary.appointments' => 'appointments',
    ],
];
