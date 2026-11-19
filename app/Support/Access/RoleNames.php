<?php

namespace App\Support\Access;

/**
 * What clinics see a role called. The stored names stay as they are (imports, old records and the
 * platform side still use them); only the words on clinic screens change.
 */
final class RoleNames
{
    private const LABELS = [
        RoleMap::OWNER => 'Owner',
        'Staff' => 'Staff (attendance only)',
    ];

    /** Order clinics see roles in: Owner first, then the rest by name. */
    private const FIRST = [RoleMap::OWNER, 'Manager'];

    public static function label(?string $name): string
    {
        return self::LABELS[$name] ?? (string) $name;
    }

    /** @param iterable<string> $names */
    public static function labels(iterable $names, string $glue = ', '): string
    {
        return collect($names)->map(fn ($name) => self::label($name))->implode($glue);
    }

    /** Sort key for a role name: Owner, Manager, then alphabetical by label. */
    public static function sortKey(?string $name): string
    {
        $position = array_search($name, self::FIRST, true);

        return ($position === false ? '9' : (string) $position).self::label($name);
    }
}
