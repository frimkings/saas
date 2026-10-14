<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDepartment;
use App\Models\AttendanceStaff;
use App\Services\OpticalLensExcelImportService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A staff list from Excel (.xlsx) or CSV: one row per person with their Employee ID as it is on
 * the terminal, their name and, if wanted, their department, how they are paid and the day to
 * count them from. People already known by that Employee ID are updated; departments named in
 * the file and not yet at the branch are made.
 */
class AttendanceStaffImport
{
    public const MAX_ROWS = 2000;

    /** Column headings understood, with spaces and punctuation ignored. */
    private const COLUMNS = [
        'id' => ['employeeid', 'employeeno', 'employeenumber', 'staffid', 'staffno', 'id'],
        'name' => ['name', 'fullname', 'staffname', 'employeename'],
        'department' => ['department', 'dept', 'unit', 'ward'],
        'pay_type' => ['paytype', 'paid', 'pay'],
        'pay_rate' => ['payrate', 'rate', 'salary', 'amount'],
        'starts_on' => ['startdate', 'countfrom', 'start', 'datejoined'],
    ];

    public const TEMPLATE = "Employee ID,Name,Department,Pay type,Pay rate,Start date\r\n001,Ama Owusu,Nursing,monthly,2200,2026-01-05\r\n002,Kofi Boateng,Laboratory,hourly,20,\r\n";

    /** The file's rows as lists of cells, heading row first. */
    public function rows($upload, string $field = 'staffFile'): array
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        if ($extension === 'xlsx') {
            try {
                $sheet = app(OpticalLensExcelImportService::class)->readUpload($upload)[0]['rows'] ?? [];
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([$field => collect($e->errors())->flatten()->first() ?? 'That file could not be read.']);
            }
            ksort($sheet);
            $rows = [];
            foreach ($sheet as $cells) {
                $row = [];
                for ($col = 1; $col <= max(array_keys($cells) ?: [0]); $col++) {
                    $row[] = $cells[$col] ?? '';
                }
                $rows[] = $row;
            }

            return $rows;
        }
        if (! in_array($extension, ['csv', 'txt'], true)) {
            throw ValidationException::withMessages([$field => 'Upload an Excel file (.xlsx) or a CSV file.']);
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $upload->get());
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $delimiter = substr_count($lines[0] ?? '', ';') > substr_count($lines[0] ?? '', ',') ? ';' : ',';

        return array_map(fn ($line) => array_map('trim', str_getcsv($line, $delimiter, '"', '\\')), array_values(array_filter($lines, fn ($line) => trim($line) !== '')));
    }

    /**
     * Bring the rows in. Pay columns are read only when $withPay (the owner).
     *
     * @return array{added: int, updated: int, departments: int, skipped: array<int, string>}
     */
    public function import(array $rows, bool $withPay): array
    {
        $map = $this->columns(array_shift($rows) ?? []);
        if (! isset($map['id'], $map['name'])) {
            throw ValidationException::withMessages(['staffFile' => 'The first row must be headings, with at least "Employee ID" and "Name". Use the template.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['staffFile' => 'That is '.number_format(count($rows)).' people. Bring in up to '.number_format(self::MAX_ROWS).' at a time.']);
        }

        $departments = AttendanceDepartment::query()->get()->keyBy(fn ($department) => mb_strtolower($department->name));
        $known = AttendanceStaff::query()->get()->keyBy('employee_no');
        $result = ['added' => 0, 'updated' => 0, 'departments' => 0, 'not_followed' => 0, 'skipped' => []];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $cell = fn (string $column) => isset($map[$column]) ? trim((string) ($row[$map[$column]] ?? '')) : '';
            // Excel keeps "001" as text when written ="001".
            $id = preg_replace('/^="?(.*?)"?$/', '$1', $cell('id'));
            if ($id === '' && $cell('name') === '') {
                continue;
            }
            if ($id === '' || mb_strlen($id) > 32) {
                $result['skipped'][$line] = $id === '' ? 'no Employee ID' : 'the Employee ID is too long';

                continue;
            }
            $staff = $known[$id] ?? null;
            if (! $staff && $cell('name') === '') {
                $result['skipped'][$line] = 'no name';

                continue;
            }

            $values = array_filter(['name' => mb_substr($cell('name'), 0, 255)], fn ($value) => $value !== '');
            if ($name = mb_substr($cell('department'), 0, 60)) {
                if (! $department = $departments[mb_strtolower($name)] ?? null) {
                    $departments[mb_strtolower($name)] = $department = AttendanceDepartment::create(['name' => $name]);
                    $result['departments']++;
                }
                $values['attendance_department_id'] = $department->id;
            }
            if ($withPay && ($type = $this->payType($cell('pay_type'))) && is_numeric($rate = str_replace([',', ' '], '', $cell('pay_rate'))) && $rate >= 0) {
                $values += ['pay_type' => $type, 'pay_rate' => round((float) $rate, 2)];
            }
            if ($cell('starts_on') !== '') {
                if (! $date = $this->date($cell('starts_on'))) {
                    $result['skipped'][$line] = 'the start date "'.$cell('starts_on').'" was not understood (the rest of the row was read)';
                } else {
                    $values['starts_on'] = $date;
                }
            }

            if ($staff) {
                $staff->update($values);
                $result['updated']++;
            } else {
                $known[$id] = AttendanceStaff::create($values + ['employee_no' => $id, 'is_tracked' => true]);
                $result['added']++;
                // Past the plan's limit they are kept, not followed.
                $result['not_followed'] += (int) ! $known[$id]->is_tracked;
            }
        }

        return $result;
    }

    /** Which column of the file holds what, from its heading row. */
    private function columns(array $headings): array
    {
        $map = [];
        foreach ($headings as $index => $heading) {
            $heading = preg_replace('/[^a-z]/', '', mb_strtolower((string) $heading));
            foreach (self::COLUMNS as $column => $names) {
                if (! isset($map[$column]) && in_array($heading, $names, true)) {
                    $map[$column] = $index;
                }
            }
        }

        return $map;
    }

    private function payType(string $value): ?string
    {
        $value = mb_strtolower($value);

        return match (true) {
            str_contains($value, 'month') || str_contains($value, 'salar') => 'monthly',
            str_contains($value, 'hour') => 'hourly',
            default => null,
        };
    }

    /** '2026-01-05', '5/1/2026' (day first) or an Excel day number. */
    private function date(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (ctype_digit($value) && (int) $value > 20000 && (int) $value < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d');
        }

        return null;
    }
}
