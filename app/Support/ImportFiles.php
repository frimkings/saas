<?php

namespace App\Support;

use App\Models\PatientDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where uploaded import files (another system's patient exports) are kept between the check and the
 * import. A server's own disk is not shared between Laravel Cloud machines and is wiped on deploy, so
 * hosted installs keep them in the private documents bucket; offline installs keep the local disk.
 *
 * Each stored file records its disk ('disk' beside 'path'); files stored before this have none and are
 * on the local disk. The readers need a real file path, so a file in the bucket is copied to the
 * server's local disk while it is read, and the copies are removed with forgetCopies().
 */
final class ImportFiles
{
    private const COPIES = 'import-copies';

    /** @var array<int, string> */
    private static array $copies = [];

    /** The disk new uploads go to: IMPORTS_DISK when set, else the documents bucket when attached, else local. */
    public static function disk(): string
    {
        if ($disk = config('filesystems.imports_disk')) return $disk;

        return config('filesystems.disks.'.PatientDocument::BUCKET_DISK.'.bucket') ? PatientDocument::BUCKET_DISK : 'local';
    }

    /** A local path holding the stored file's contents. */
    public static function localPath(array $file): string
    {
        $disk = $file['disk'] ?? 'local';
        if (self::isLocal($disk)) return Storage::disk($disk)->path($file['path']);

        $copy = self::COPIES.'/'.sha1($disk.':'.$file['path']).'.'.strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
        $local = Storage::disk('local');
        if (! $local->exists($copy)) {
            $stream = Storage::disk($disk)->readStream($file['path']);
            if (! $stream) throw new RuntimeException(($file['name'] ?? 'A file').' could not be read from storage. Upload the files again.');
            try {
                $local->writeStream($copy, $stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
        }
        self::$copies[] = $copy;

        return $local->path($copy);
    }

    /** Removes the local copies made while reading (the stored files stay). */
    public static function forgetCopies(): void
    {
        if (self::$copies) Storage::disk('local')->delete(array_unique(self::$copies));
        self::$copies = [];
    }

    /** Removes a batch's stored folder from every disk its files were kept on. */
    public static function deleteDirectory(string $dir, array $files): void
    {
        $disks = collect($files)->map(fn ($f) => $f['disk'] ?? 'local')->push('local')->unique();
        foreach ($disks as $disk) Storage::disk($disk)->deleteDirectory($dir);
    }

    private static function isLocal(string $disk): bool
    {
        return $disk === 'local' || config('filesystems.disks.'.$disk.'.driver') === 'local';
    }
}
