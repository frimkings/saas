<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRoster;
use App\Models\AttendanceStaff;
use App\Support\ShiftCodes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A department's roster as a spreadsheet, to fill in Excel and bring back: one row per person
 * (Employee ID, name), one column per day, each cell a shift's code or name, OFF for a day off,
 * USUAL to go back to their usual days, or empty for no change. Days before today are never changed.
 */
class AttendanceRosterFile
{
    public const MAX_DAYS = 62;

    /** The file to fill in, with what each person is down for now. */
    public function template(Collection $staff, CarbonImmutable $first, CarbonImmutable $last, array $codes): string
    {
        $days = $this->days($first, $last);
        $current = $this->current($staff, $first, $last);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Employee ID', 'Name', ...array_map(fn ($day) => $day->format('Y-m-d D'), $days)]);
        foreach ($staff->sortBy('name') as $person) {
            fputcsv($out, [ctype_digit((string) $person->employee_no) && str_starts_with((string) $person->employee_no, '0') ? '="'.$person->employee_no.'"' : $person->employee_no,
                $person->name, ...array_map(fn ($day) => $this->code($current[$person->id][$day->format('Y-m-d')] ?? null, $codes), $days)]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Codes:', implode(', ', array_map(fn ($shift) => $shift['code'].' = '.$shift['name'], $codes)).', OFF = day off, USUAL = their usual days, empty = no change']);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * What a filled-in file would change: ['changes' => [[staff id, name, 'Y-m-d', shift id | 0 | 'usual']],
     * 'problems' => [message], 'people' => int, 'from' => 'Y-m-d', 'to' => 'Y-m-d']. Only $staff can be changed.
     */
    public function read(array $rows, Collection $staff, array $codes, CarbonImmutable $today): array
    {
        $result = ['changes' => [], 'problems' => [], 'people' => 0, 'from' => null, 'to' => null];
        $heading = array_shift($rows) ?? [];
        $dates = [];
        foreach ($heading as $index => $cell) {
            if ($index >= 2 && $date = $this->date((string) $cell)) {
                $dates[$index] = $date;
            }
        }
        if (! $dates) {
            $result['problems'][] = 'No days were found in the first row. Use the template: the first row has the dates.';

            return $result;
        }
        if (count($dates) > self::MAX_DAYS) {
            $result['problems'][] = 'The file has '.count($dates).' days; bring in up to '.self::MAX_DAYS.' at a time.';

            return $result;
        }
        $byId = $staff->keyBy(fn ($person) => (string) $person->employee_no);
        $byName = $staff->keyBy(fn ($person) => mb_strtolower(trim($person->name)));
        $current = $this->current($staff, CarbonImmutable::parse(min($dates)), CarbonImmutable::parse(max($dates)));
        $past = 0;
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $id = preg_replace('/^="?(.*?)"?$/', '$1', trim((string) ($row[0] ?? '')));
            $name = trim((string) ($row[1] ?? ''));
            if ($id === '' && $name === '') {
                continue;
            }
            if (str_starts_with(strtolower($id), 'codes')) {
                break;
            }
            $person = $byId[$id] ?? $byName[mb_strtolower($name)] ?? null;
            if (! $person) {
                $result['problems'][] = "Row {$line}: ".($name ?: $id).' is not in this department, or not followed.';

                continue;
            }
            $changed = false;
            foreach ($dates as $index => $date) {
                $choice = ShiftCodes::read((string) ($row[$index] ?? ''), $codes);
                if ($choice === null) {
                    continue;
                }
                if ($choice === false) {
                    $result['problems'][] = "Row {$line}, {$date}: \"".trim((string) $row[$index]).'" is not a shift. Use '.implode(', ', array_column($codes, 'code')).', OFF or USUAL.';

                    continue;
                }
                $now = $current[$person->id][$date] ?? null;
                $rostered = $current['roster'][$person->id][$date] ?? null;
                if (($choice === 'usual' && $rostered === null) || ($choice !== 'usual' && $choice === $now)) {
                    continue;
                }
                if ($date < $today->format('Y-m-d')) {
                    $past++;

                    continue;
                }
                $result['changes'][] = [$person->id, $person->name, $date, $choice];
                $changed = true;
            }
            $result['people'] += (int) $changed;
        }
        if ($past) {
            $result['problems'][] = "{$past} ".($past === 1 ? 'change is' : 'changes are').' for days before today; past days are left as they were.';
        }
        $result['from'] = min($dates);
        $result['to'] = max($dates);

        return $result;
    }

    /** Make the changes read from a file. */
    public function apply(array $changes, ?int $userId): int
    {
        \App\Support\AttendanceAudit::quietly(fn () => DB::transaction(function () use ($changes, $userId) {
            foreach ($changes as [$staffId, $name, $date, $choice]) {
                if ($choice === 'usual') {
                    AttendanceRoster::query()->where('attendance_staff_id', $staffId)->whereDate('date', $date)->delete();

                    continue;
                }
                AttendanceRoster::query()->updateOrCreate(['attendance_staff_id' => $staffId, 'date' => $date],
                    ['attendance_shift_id' => $choice ?: null, 'note' => null, 'set_by' => $userId]);
            }
        }));

        return count($changes);
    }

    /** @return list<CarbonImmutable> */
    private function days(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $days = [];
        for ($day = $first->startOfDay(); $day->lte($last) && count($days) < self::MAX_DAYS; $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * Staff id => 'Y-m-d' => what they are down for: a shift id, 0 for off, or null for their
     * usual hours. ['roster'] holds just what the roster itself sets.
     */
    private function current(Collection $staff, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $current = ['roster' => []];
        AttendanceRoster::query()->whereIn('attendance_staff_id', $staff->pluck('id'))->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])
            ->toBase()->get(['attendance_staff_id', 'date', 'attendance_shift_id'])
            ->each(function ($entry) use (&$current) {
                $current['roster'][$entry->attendance_staff_id][substr($entry->date, 0, 10)] = (int) ($entry->attendance_shift_id ?? 0);
            });
        foreach ($staff as $person) {
            $week = $person->shift_week ?? [];
            for ($day = $first->startOfDay(); $day->lte($last); $day = $day->addDay()) {
                $key = $day->format('Y-m-d');
                $weekly = $week[$day->dayOfWeekIso] ?? $week[(string) $day->dayOfWeekIso] ?? null;
                $current[$person->id][$key] = $current['roster'][$person->id][$key] ?? ($weekly === null || $weekly === '' ? null : (int) $weekly);
            }
        }

        return $current;
    }

    private function code(?int $choice, array $codes): string
    {
        return match (true) {
            $choice === null => '',
            $choice === 0 => ShiftCodes::OFF,
            default => $codes[$choice]['code'] ?? '',
        };
    }

    /** A heading cell as 'Y-m-d': '2026-10-13 Tue', '13/10/2026' (day first), or an Excel day number. */
    private function date(string $cell): ?string
    {
        $cell = trim($cell);
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $cell, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $cell, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (is_numeric($cell) && (int) $cell > 40000 && (int) $cell < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $cell)->format('Y-m-d');
        }

        return null;
    }
}
