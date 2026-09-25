<?php

namespace App\Support;

class MysqlDumpPath
{
    public static function resolve(?string $configured = null): ?string
    {
        $configured = trim((string) $configured);
        if ($configured !== '' && static::hasDumpBinary($configured)) {
            return static::normalize($configured);
        }

        return static::detect();
    }

    public static function detect(): ?string
    {
        foreach (static::candidatePaths() as $path) {
            if (static::hasDumpBinary($path)) {
                return static::normalize($path);
            }
        }

        return null;
    }

    public static function executable(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $file = rtrim(str_replace('\\', '/', $path), '/') . '/mysqldump.exe';

        return is_file($file) ? $file : null;
    }

    public static function hasDumpBinary(string $path): bool
    {
        return static::executable($path) !== null;
    }

    public static function candidatePaths(): array
    {
        $paths = [];
        $basePath = str_replace('\\', '/', base_path());
        $isXampp = str_contains(strtolower($basePath), '/xampp/');
        $isLaragon = str_contains(strtolower($basePath), '/laragon/');

        $xampp = ['C:/xampp/mysql/bin'];
        $laragon = static::globPaths('C:/laragon/bin/mysql/*/bin');
        $wamp = static::globPaths('C:/wamp64/bin/mysql/*/bin');
        $mysqlProgramFiles = array_merge(
            static::globPaths('C:/Program Files/MySQL/MySQL Server */bin'),
            static::globPaths('C:/Program Files (x86)/MySQL/MySQL Server */bin'),
            static::globPaths('C:/Program Files/MariaDB */bin')
        );

        if ($isXampp) {
            $paths = array_merge($paths, $xampp, $laragon);
        } elseif ($isLaragon) {
            $paths = array_merge($paths, $laragon, $xampp);
        } else {
            $paths = array_merge($paths, $laragon, $xampp);
        }

        $paths = array_merge($paths, $wamp, $mysqlProgramFiles, static::pathEnvironmentBins());

        return array_values(array_unique(array_map([static::class, 'normalize'], array_filter($paths))));
    }

    private static function globPaths(string $pattern): array
    {
        $paths = glob($pattern, GLOB_ONLYDIR) ?: [];
        natsort($paths);

        return array_reverse(array_values($paths));
    }

    private static function pathEnvironmentBins(): array
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));

        return array_filter($paths, fn ($path) => static::hasDumpBinary((string) $path));
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
