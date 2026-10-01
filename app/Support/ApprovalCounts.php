<?php

namespace App\Support;

use App\Models\ClearanceRevokeLog;
use App\Models\DiscountApprovalRequest;
use App\Models\PasswordResetRequest;
use App\Models\RefundLog;
use App\Support\Tenancy\TenantCache;
use Illuminate\Support\Facades\Cache;

/**
 * Pending approval counts for the admin sidebar badge and dashboard, cached per branch for a
 * minute so they aren't recounted on every page. Saving or deleting any of these requests
 * clears the cache (see each model's booted()), so a change shows on the next page.
 */
class ApprovalCounts
{
    private const TTL_SECONDS = 60;

    /** @return array{discount: int, refund: int, revoke: int, password: int} */
    public static function pending(): array
    {
        return Cache::remember(self::key(), self::TTL_SECONDS, fn () => [
            'discount' => DiscountApprovalRequest::where('status', DiscountApprovalRequest::STATUS_PENDING)->count(),
            'refund' => RefundLog::pendingCount(),
            'revoke' => ClearanceRevokeLog::pendingCount(),
            'password' => PasswordResetRequest::pendingCount(),
        ]);
    }

    public static function total(): int
    {
        return array_sum(self::pending());
    }

    public static function forget(): void
    {
        Cache::forget(self::key());
    }

    private static function key(): string
    {
        return TenantCache::key('approvals:pending', true);
    }
}
