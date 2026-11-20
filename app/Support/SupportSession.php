<?php

namespace App\Support;

/**
 * A platform administrator inspecting one clinic's own interface ("Enter as support").
 *
 * Support access never adds the admin to clinic_user or branch_user, so they are absent from every
 * clinic's user list and user count and from all future clinics with nothing to seed. The session
 * just remembers which clinic is being inspected; ResolveTenantContext resolves it and grants the
 * Super Admin role in memory for that request only. Each entry and exit is written to the platform
 * audit trail, and a banner shows while a support session is open (decided 2026-11-19).
 */
class SupportSession
{
    public const KEY = 'support_clinic_id';

    /** The clinic being inspected, or null. Only a platform administrator can hold a support session. */
    public static function clinicId(): ?int
    {
        if (! auth()->check() || ! auth()->user()->is_platform_admin) {
            return null;
        }
        $id = session(self::KEY);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function active(): bool
    {
        return self::clinicId() !== null;
    }
}
