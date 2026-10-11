<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Records whatever an attendance terminal posts, to learn its format (JSON, multipart or XML)
 * before punches are read from it. Off unless ATTENDANCE_CAPTURE_TOKEN is set; read the result
 * with `php artisan attendance:captures`.
 */
class AttendanceCaptureController extends Controller
{
    private const KEEP = 200;
    private const MAX_BODY = 65535;

    public function __invoke(Request $request, string $token): Response
    {
        $expected = (string) config('services.attendance.capture_token');
        abort_unless($expected !== '' && hash_equals($expected, $token), 404);

        DB::table('attendance_captures')->insert([
            'method' => $request->method(),
            'secure' => $request->secure(),
            'ip' => $request->ip(),
            'content_type' => mb_substr((string) $request->header('Content-Type'), 0, 255) ?: null,
            'headers' => json_encode(Arr::except($request->headers->all(), ['authorization', 'cookie'])),
            'fields' => json_encode($this->truncated($request->post())),
            // A face photo is biometric data: note that one came, never keep it.
            'files' => json_encode($this->describeFiles($request->allFiles())),
            // Empty for multipart/form-data, which PHP has already parsed into fields and files.
            'body' => mb_strcut($request->getContent(), 0, self::MAX_BODY) ?: null,
            'received_at' => now(),
        ]);

        $oldestKept = DB::table('attendance_captures')->orderByDesc('id')->skip(self::KEEP - 1)->value('id');
        if ($oldestKept) {
            DB::table('attendance_captures')->where('id', '<', $oldestKept)->delete();
        }

        // The terminal resends anything not answered with 200.
        return response('OK', 200);
    }

    private function truncated(array $fields): array
    {
        return array_map(
            fn ($value) => is_array($value) ? $this->truncated($value) : mb_strcut((string) $value, 0, self::MAX_BODY),
            $fields,
        );
    }

    private function describeFiles(array $files): array
    {
        return array_map(fn ($file) => is_array($file) ? $this->describeFiles($file) : [
            'name' => $file->getClientOriginalName(),
            'type' => $file->getClientMimeType(),
            'bytes' => $file instanceof UploadedFile && $file->isValid() ? $file->getSize() : null,
        ], $files);
    }
}
