<?php

namespace App\Support\Access;

use App\Models\User;

/**
 * The one question every screen and action asks: may this person do this? A person may when one
 * of their roles carries the permission (RoleMap), or when they hold one of its legacy database
 * permissions. Per-person switches per clinic come in phase 4.
 */
final class Access
{
    /** Anyone who isn't a signed-in clinic user (a guest, a stand-in object) may do nothing. */
    public static function allows(mixed $user, string $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (RoleMap::grants($user->getRoleNames(), $permission)) {
            return true;
        }

        $legacy = Permissions::legacyNames($permission);

        return $legacy !== [] && $user->getAllPermissions()->pluck('name')->intersect($legacy)->isNotEmpty();
    }

    /** @param iterable<string> $permissions any one is enough */
    public static function allowsAny(mixed $user, iterable $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** The signed-in person; any one of the permissions is enough. */
    public static function can(string ...$permissions): bool
    {
        return self::allowsAny(auth()->user(), $permissions);
    }

    /** False for routes missing from the screen map: anything unlisted is refused. */
    public static function allowsRoute(mixed $user, ?string $routeName): bool
    {
        $permissions = Screens::permissionsFor($routeName);

        return $permissions !== null && self::allowsAny($user, $permissions);
    }
}
