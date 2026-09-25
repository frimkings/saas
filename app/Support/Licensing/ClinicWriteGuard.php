<?php

namespace App\Support\Licensing;

use App\Services\ClinicAccessService;
use App\Support\Tenancy\TenantContext;

class ClinicWriteGuard
{
    // Infrastructure, authentication, audit and renewal records must stay writable.
    private const EXEMPT = [
        'settings', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches',
        'password_reset_tokens', 'personal_access_tokens', 'login_logs', 'login_logs_archive',
        'clinic_user', 'branch_user', 'branch_user_role', 'model_has_roles', 'model_has_permissions',
        'audit_trails', 'audit_trails_archive', 'app_notifications', 'notifications',
        'platform_audit_logs', 'clinic_subscriptions', 'subscription_change_requests',
        'billing_notification_logs', 'report_deliveries', 'migrations',
    ];

    public function check(string $sql): void
    {
        if (!app(TenantContext::class)->resolved()) return;
        if (!preg_match('/^\s*(?:insert(?:\s+ignore)?\s+into|replace\s+into|update|delete\s+from|truncate(?:\s+table)?)\s+[`"\[]?([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $match)) return;
        if (in_array(strtolower($match[1]), self::EXEMPT, true)) return;
        if (preg_match('/^(subscription_|platform_|billing_)/', strtolower($match[1]))) return;
        $operationalFields = match (strtolower($match[1])) {
            'users' => ['password', 'remember_token', 'must_change_password', 'preferred_workspace', 'last_login_at', 'updated_at'],
            'clinics' => ['billing_legal_name', 'billing_tax_id', 'billing_address', 'billing_email', 'billing_phone', 'updated_at'],
            default => [],
        };
        if ($operationalFields && preg_match('/^\s*update\b.*?\bset\b(.*?)\bwhere\b/is', $sql, $set)) {
            preg_match_all('/(?:^|,)\s*[`"]?(\w+)[`"]?\s*=/', $set[1], $fields);
            if ($fields[1] && !array_diff($fields[1], $operationalFields)) return;
        }
        app(ClinicAccessService::class)->assertWritable();
    }
}
