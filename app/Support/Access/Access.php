<?php

namespace App\Support\Access;

use App\Models\User;

/**
 * The one question every screen and action asks: may this person do this? A person may when one
 * of their roles carries the permission (RoleMap), or when they hold one of its legacy database
 * permissions, unless their own switch in this clinic says otherwise (PersonalAccess).
 */
final class Access
{
    /** Anyone who isn't a signed-in clinic user (a guest, a stand-in object) may do nothing. */
    public static function allows(mixed $user, string $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $roles = $user->getRoleNames();
        // The Owner can't be switched off anything.
        if ($roles->contains(RoleMap::OWNER)) {
            return true;
        }

        // This person's own switch in this clinic wins over their role (phase 4).
        $switch = PersonalAccess::override($user, $permission);
        if ($switch !== null) {
            return $switch;
        }

        return PersonalAccess::fromRole($user, $permission);
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
