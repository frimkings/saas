<?php

namespace App\Support\Access;

use App\Models\User;

/**
 * Money rule: whoever asks for a discount, refund, revoke or correction may not also approve it.
 * The Owner is the one exception (the last word in their own business, and often the only
 * approver in a small clinic); their own approvals are marked in the audit trail.
 */
final class MakerChecker
{
    public const MESSAGE = 'You made this request, so another Manager or the Owner must approve it.';

    /** Why this person may not approve, or null when they may. */
    public static function problem(?User $approver, int|string|null $requesterId): ?string
    {
        if (! $approver || ! self::isOwnRequest($approver, $requesterId)) {
            return null;
        }

        return self::isOwner($approver) ? null : self::MESSAGE;
    }

    public static function isOwnRequest(?User $approver, int|string|null $requesterId): bool
    {
        return $approver && $requesterId !== null && $requesterId !== '' && (int) $requesterId === (int) $approver->id;
    }

    /** True when the Owner approves their own request: worth a line in the audit trail. */
    public static function isSelfApproval(?User $approver, int|string|null $requesterId): bool
    {
        return self::isOwnRequest($approver, $requesterId) && self::isOwner($approver);
    }

    private static function isOwner(User $user): bool
    {
        return $user->getRoleNames()->contains(RoleMap::OWNER);
    }
}
