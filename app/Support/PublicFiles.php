<?php

namespace App\Support;

use App\Models\PatientDocument;

/**
 * Where clinic logos, profile pictures and expense receipts (the "public" disk) are kept.
 *
 *  - A public bucket, when PUBLIC_AWS_BUCKET is set (config/filesystems.php).
 *  - On Laravel Cloud without one: the bucket attached for patient documents, under its own
 *    folder. The server disk there is wiped on every deploy, so it can't hold them.
 *  - Otherwise (offline installs, local development): the server's storage/app/public.
 *
 * Pages link to these files as /storage/{path}. Where storage/app/public is linked into the
 * web root the web server answers; everywhere else the app serves them (routes/web.php).
 */
class PublicFiles
{
    /** Folder inside the shared bucket, apart from patient documents. */
    public const BUCKET_FOLDER = 'public-files';

    /** Called once at start-up, after Laravel Cloud has added its bucket disks. */
    public static function configure(): void
    {
        if (config('filesystems.disks.public.driver') !== 'local') {
            return;
        }
        $bucket = config('filesystems.disks.'.PatientDocument::BUCKET_DISK);
        if (! is_array($bucket) || empty($bucket['bucket'])) {
            return;
        }
        config(['filesystems.disks.public' => ['root' => self::BUCKET_FOLDER, 'visibility' => 'private', 'throw' => false] + $bucket]);
    }

    /**
     * Files the app may serve to anyone with the link: clinic branding and profile pictures,
     * under names nobody can guess. Receipts are served by their own signed-in routes.
     */
    public static function servable(string $path): bool
    {
        return preg_match('#^(clinics/[A-Za-z0-9-]+/branding|avatars)/[A-Za-z0-9._-]+$#', $path) === 1 && ! str_contains($path, '..');
    }
}
