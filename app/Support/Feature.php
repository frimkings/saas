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
}
