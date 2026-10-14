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
    // On every plan saved since attendance was split into parts. A list without it (older plans,
    // older offline keys) has attendance whole: every part comes with it.
    const ATTENDANCE_PARTS_CHOSEN = 'attendance_parts_chosen';
}
