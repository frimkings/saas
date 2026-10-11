<?php

namespace App\Services\Attendance;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Reads what a Hikvision access terminal posts to its "HTTP listening" address. Depending on the
 * firmware an event arrives as JSON, as XML (EventNotificationAlert), or as a multipart form with
 * the event in one part and a face photo in another. The photo is never read.
 *
 * Returns the events found, each ['type', 'time' => 'Y-m-d H:i:s' by the terminal's own clock,
 * 'employee_no', 'name', 'major', 'minor'].
 */
class HikvisionEventReader
{
    private const MAX_TEXT = 262144;

    public function events(Request $request): array
    {
        $events = [];
        foreach ($this->texts($request) as $text) {
            $event = $this->decode($text);
            if ($event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** Every piece of the request that could hold an event: the body, form fields and small non-image parts. */
    private function texts(Request $request): array
    {
        $texts = [];
        $body = (string) $request->getContent();
        if ($body !== '' && strlen($body) <= self::MAX_TEXT) {
            $texts[] = $body;
        }
        $fields = $request->post();
        array_walk_recursive($fields, function ($value) use (&$texts) {
            if (is_string($value) && $value !== '' && strlen($value) <= self::MAX_TEXT) {
                $texts[] = $value;
            }
        });
        $files = $request->allFiles();
        array_walk_recursive($files, function ($file) use (&$texts) {
            // Some firmware sends the event itself as a file part; a photo is skipped unread.
            if ($file instanceof UploadedFile && $file->isValid() && $file->getSize() <= self::MAX_TEXT
                && ! str_starts_with((string) $file->getClientMimeType(), 'image/')) {
                $texts[] = (string) file_get_contents($file->getRealPath());
            }
        });

        return $texts;
    }

    private function decode(string $text): ?array
    {
        $text = trim($text);
        if (str_starts_with($text, '{')) {
            $data = json_decode($text, true);

            return is_array($data) ? $this->normalise($data) : null;
        }
        if (str_starts_with($text, '<') && ! preg_match('/<!(DOCTYPE|ENTITY)/i', $text)) {
            $xml = @simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

            return $xml === false ? null : $this->normalise(json_decode(json_encode($xml), true) ?: []);
        }

        return null;
    }

    private function normalise(array $data): ?array
    {
        $type = $data['eventType'] ?? null;
        if (! is_string($type) || $type === '') {
            return null;
        }
        $detail = is_array($data['AccessControllerEvent'] ?? null) ? $data['AccessControllerEvent'] : [];
        $text = fn ($value) => is_scalar($value) ? trim((string) $value) : '';
        $employeeNo = $text($detail['employeeNoString'] ?? '') ?: $text($detail['employeeNo'] ?? '');

        return [
            'type' => $type,
            'time' => $this->clockTime($text($data['dateTime'] ?? '')),
            'employee_no' => $employeeNo !== '' ? mb_substr($employeeNo, 0, 32) : null,
            'name' => $text($detail['name'] ?? '') ?: null,
            'major' => is_numeric($detail['majorEventType'] ?? null) ? (int) $detail['majorEventType'] : null,
            'minor' => is_numeric($detail['subEventType'] ?? null) ? (int) $detail['subEventType'] : null,
        ];
    }

    /** "2026-10-12T07:55:03+00:00" -> "2026-10-12 07:55:03": the terminal's wall clock, whatever zone it claims. */
    private function clockTime(string $value): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/', $value, $m)) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
            return null;
        }

        return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
    }

    /** A person identified at the terminal: an access event (major type 5) that names an employee. */
    public static function isPunch(array $event): bool
    {
        return $event['type'] === 'AccessControllerEvent' && $event['employee_no'] !== null && $event['time'] !== null
            && ($event['major'] === null || $event['major'] === 5);
    }
}
