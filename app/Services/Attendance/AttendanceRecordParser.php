<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Reads the "Attendance Record" report an attendance terminal exports: a grid with one row per
 * person and one column per day, each cell listing that day's punch times. Returns
 * ['period_start', 'period_end', 'exported_at', 'staff' => [['employee_no', 'name', 'department',
 * 'punches' => ['Y-m-d H:i:s', ...]]]].
 */
class AttendanceRecordParser
{
    public function __construct(private readonly XlsReader $reader)
    {
    }

    public function parseUpload($upload): array
    {
        // A short local path also works with remote upload disks and Windows long filenames.
        $path = tempnam(sys_get_temp_dir(), 'att_');
        try {
            file_put_contents($path, $upload->get());

            return $this->parse($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function parse(string $path): array
    {
        try {
            $rows = $this->reader->read($path);
        } catch (RuntimeException $e) {
            throw $this->invalid($e->getMessage());
        }

        $period = null;
        $exportedAt = null;
        $headerRow = null;
        foreach ($rows as $index => $row) {
            $first = trim((string) ($row[0] ?? ''));
            if (preg_match('~^Made Date\s*:\s*(\d{4})[/-](\d{1,2})[/-](\d{1,2})\s*-\s*(\d{4})[/-](\d{1,2})[/-](\d{1,2})~i', $first, $m)) {
                $period = [CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3]), CarbonImmutable::create((int) $m[4], (int) $m[5], (int) $m[6])];
            } elseif (preg_match('~^Create Time\s*:\s*(\d{4})[/-](\d{1,2})[/-](\d{1,2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?~i', $first, $m)) {
                $exportedAt = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0));
            } elseif (strcasecmp($first, 'Employee ID') === 0) {
                $headerRow = $index;
                break;
            }
        }
        if ($headerRow === null || ! $period || $period[1]->lt($period[0])) {
            throw $this->invalid('This does not look like the Attendance Record report from the terminal. Export "Attendance Record" for the month and upload that file.');
        }

        // Day columns run on from the first day of the report; the heading must agree with the date.
        $dates = [];
        $date = $period[0];
        foreach ($rows[$headerRow] as $column => $heading) {
            if ($column < 3 || ! ctype_digit(trim((string) $heading))) {
                continue;
            }
            if ($date->gt($period[1])) {
                break; // a 31-column grid for a shorter month
            }
            if ((int) trim((string) $heading) !== $date->day) {
                throw $this->invalid('The day columns in this report do not match its dates, so it was not read.');
            }
            $dates[$column] = $date;
            $date = $date->addDay();
        }

        $staff = [];
        foreach ($rows as $index => $row) {
            $employeeNo = trim((string) ($row[0] ?? ''));
            if ($index <= $headerRow || $employeeNo === '') {
                continue;
            }
            $punches = [];
            foreach ($dates as $column => $day) {
                preg_match_all('/(?<!\d)([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?(?!\d)/', (string) ($row[$column] ?? ''), $times, PREG_SET_ORDER);
                foreach ($times as $time) {
                    $punches[] = $day->setTime((int) $time[1], (int) $time[2], (int) ($time[3] ?? 0))->format('Y-m-d H:i:s');
                }
            }
            $staff[$employeeNo] = [
                'employee_no' => mb_substr($employeeNo, 0, 32),
                'name' => mb_substr(trim((string) ($row[1] ?? '')) ?: $employeeNo, 0, 255),
                'department' => mb_substr(trim((string) ($row[2] ?? '')), 0, 255) ?: null,
                'punches' => array_values(array_unique($punches)),
            ];
        }
        if (! $staff) {
            throw $this->invalid('No staff were found in this report.');
        }

        return [
            'period_start' => $period[0],
            'period_end' => $period[1],
            // An undated report is taken as complete only up to its last punch.
            'exported_at' => $exportedAt ?? CarbonImmutable::parse(collect($staff)->flatMap(fn ($person) => $person['punches'])->max() ?? $period[0]->format('Y-m-d H:i:s')),
            'staff' => array_values($staff),
        ];
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
