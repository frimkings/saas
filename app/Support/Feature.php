<?php

namespace App\Support;

class Feature
{
    const OPTICAL              = 'optical';
    const CLINICAL             = 'clinical';
    const APPOINTMENTS         = 'appointments';
    const REFERRALS            = 'referrals';
    const OUTSTANDING_BALANCES = 'outstanding_balances';
    const MANUAL_BACKUP        = 'manual_backup';
    const SMS_CAMPAIGNS        = 'sms_campaigns';
    const ADVANCED_REPORTS     = 'advanced_reports';
    const INVENTORY            = 'inventory';
    const APPROVALS            = 'approvals';
    const AUDIT_TRAIL          = 'audit_trail';
    const SCHEDULED_BACKUPS    = 'scheduled_backups';
    const EXPENSE_TRACKING     = 'expense_tracking';
    const REPORT_DELIVERY      = 'report_delivery';
    const SPECTACLES_PRO       = 'spectacles_pro';
    const UNLIMITED_USERS      = 'unlimited_users';
    // Sales summary emails to the clinic owner (App\Services\OwnerSummaryService).
    const DAILY_SUMMARY        = 'owner_daily_summary';
    const WEEKLY_SUMMARY       = 'owner_weekly_summary';
    const MONTHLY_SUMMARY      = 'owner_monthly_summary';
    // Morning email to the owner: low stock, expiring stock, bills due, late lab jobs (App\Services\OwnerAlertDigestService).
    const MORNING_ALERTS       = 'owner_morning_alerts';
    // Staff attendance from a terminal: an extra on a clinic or optical plan, and what the
    // "Attendance only" product is made of (App\Support\PlanProduct).
    const ATTENDANCE           = 'attendance';
    // Marks a plan that is attendance and nothing else: no clinic, no optical shop.
    const ATTENDANCE_ONLY      = 'attendance_only';
    // Parts of attendance sold on their own, as extras on a plan or add-ons (PlanProduct::ATTENDANCE_PARTS).
    const ATTENDANCE_SHIFTS    = 'attendance_shifts';
    const ATTENDANCE_OVERTIME  = 'attendance_overtime';
    const ATTENDANCE_LEAVE     = 'attendance_leave_types';
    const ATTENDANCE_PAYROLL   = 'attendance_payroll';
    const ATTENDANCE_PHONE     = 'attendance_phone';
    const ATTENDANCE_TRENDS    = 'attendance_trends';
    const ATTENDANCE_HOSPITAL  = 'attendance_hospital';
    // Packs of extra staff on attendance, bought as an add-on in quantity; never a plan tick.
    const ATTENDANCE_STAFF_PACK = 'attendance_staff_pack';
    // Packs of scan & attachment storage on top of the plan's allowance, bought as an add-on; never a plan tick.
    const SCAN_STORAGE_PACK = 'scan_storage_pack';
    // On every plan saved since attendance was split into parts. A list without it (older plans,
    // older offline keys) has attendance whole: every part comes with it.
    const ATTENDANCE_PARTS_CHOSEN = 'attendance_parts_chosen';

    // Clinic features sold by plan tier (2026-10-24). Before then every clinic plan had them all.
    const HISTORY_CHARTS       = 'history_charts';       // VA/IOP charts and risk flags on the History tab
    const CLINICAL_PDFS        = 'clinical_pdfs';        // visit summary, medical record and consultation PDFs
    const CLINICAL_DOCUMENTS   = 'clinical_documents';   // scan and attachment uploads
    const SALES_EXPORT         = 'sales_export';         // sales records CSV
    const DISCOUNTS            = 'discounts';            // discounts at the till
    const QUOTATIONS           = 'quotations';
    const SPECTACLE_ORDERS     = 'spectacle_orders';
    const SALES_REPORTS_MONTH  = 'sales_reports_month';  // sales reports for this month only
    const SALES_REPORTS        = 'sales_reports';        // sales reports for any dates, with PDF
    const CASH_BOOK            = 'cash_book';            // cash book and day close
    const PATIENT_LEDGER       = 'patient_ledger';
    const INSURANCE            = 'insurance';            // co-pay, coverage rules, claims, insurer payments
    const INVENTORY_ALERTS     = 'inventory_alerts';     // inventory alerts and expiring stock notices
    const PRODUCT_IMPORT       = 'product_import';       // product CSV import/export and the lens catalogue
    const PURCHASING           = 'purchasing';           // suppliers, purchase orders, stock transfers
    const PATIENT_MESSAGES     = 'patient_messages';     // SMS templates and credits, WhatsApp, clinic links
    const STAFF_MESSAGES       = 'staff_messages';       // the staff messages inbox
    const BUSINESS_OVERVIEW    = 'business_overview';    // the full owner dashboard (others get a simple one)
    const NEEDS_ATTENTION      = 'needs_attention';      // reminders panel, badges, start-of-shift summary
    const CUSTOM_ROLES         = 'custom_roles';         // roles and permissions editor
    // On every plan saved since the tiers. A list without it (older plans, offline keys) has every tier feature.
    const CLINIC_TIERS_CHOSEN  = 'clinic_tiers_chosen';
}
