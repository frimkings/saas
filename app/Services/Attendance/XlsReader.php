<?php

namespace App\Services\Attendance;

use RuntimeException;

/**
 * Reads the cells of the first sheet of an Excel 97-2003 (.xls) file, the format attendance
 * terminals export. Returns [row => [column => text]], both counted from 0. Only what those
 * reports use is read: text and plain numbers, no formulas, dates or formatting.
 */
class XlsReader
{
    private const END_OF_CHAIN = 0xFFFFFFFE;
    private const MAX_SECTORS = 200000;

    public function read(string $path): array
    {
        $file = (string) file_get_contents($path);
        if (strncmp($file, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) !== 0) {
            throw new RuntimeException('This is not an Excel 97-2003 (.xls) file.');
        }

        return $this->cells($this->workbookStream($file));
    }

    /** The workbook's bytes, out of the compound file that wraps them. */
    private function workbookStream(string $file): string
    {
        $sectorSize = 1 << $this->u16($file, 30);
        $miniSectorSize = 1 << $this->u16($file, 32);
        $miniCutoff = $this->u32($file, 56);
        $sector = fn (int $n) => substr($file, ($n + 1) * $sectorSize, $sectorSize);

        // The list of sectors holding the allocation table: 109 in the header, the rest chained.
        $tableSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $tableSectors[] = $this->u32($file, 76 + $i * 4);
        }
        $next = $this->u32($file, 68);
        for ($guard = 0; $next < self::END_OF_CHAIN && $guard < self::MAX_SECTORS; $guard++) {
            $data = $sector($next);
            for ($i = 0; $i < $sectorSize / 4 - 1; $i++) {
                $tableSectors[] = $this->u32($data, $i * 4);
            }
            $next = $this->u32($data, $sectorSize - 4);
        }
        $fat = '';
        foreach ($tableSectors as $n) {
            if ($n < self::END_OF_CHAIN) {
                $fat .= $sector($n);
            }
        }

        $chain = function (int $start, string $table, callable $read): string {
            $out = '';
            $entries = intdiv(strlen($table), 4);
            for ($n = $start, $guard = 0; $n < self::END_OF_CHAIN && $n < $entries && $guard < self::MAX_SECTORS; $guard++) {
                $out .= $read($n);
                $n = $this->u32($table, $n * 4);
            }

            return $out;
        };

        $directory = $chain($this->u32($file, 48), $fat, $sector);
        $root = null;
        $book = null;
        for ($offset = 0; $offset + 128 <= strlen($directory); $offset += 128) {
            $nameBytes = max(0, min(64, $this->u16($directory, $offset + 64)) - 2);
            $name = strtolower(mb_convert_encoding(substr($directory, $offset, $nameBytes), 'UTF-8', 'UTF-16LE'));
            $entry = ['start' => $this->u32($directory, $offset + 116), 'size' => $this->u32($directory, $offset + 120)];
            $type = ord($directory[$offset + 66]);
            if ($type === 5) {
                $root = $entry;
            } elseif ($type === 2 && in_array($name, ['workbook', 'book'], true)) {
                $book ??= $entry;
            }
        }
        if (! $book) {
            throw new RuntimeException('No workbook was found in this file.');
        }

        // Small streams live in a "mini stream" kept inside the root entry.
        if ($book['size'] < $miniCutoff && $root) {
            $miniFat = $chain($this->u32($file, 60), $fat, $sector);
            $miniStream = $chain($root['start'], $fat, $sector);
            $stream = $chain($book['start'], $miniFat, fn (int $n) => substr($miniStream, $n * $miniSectorSize, $miniSectorSize));
        } else {
            $stream = $chain($book['start'], $fat, $sector);
        }

        return substr($stream, 0, $book['size']);
    }

    private function cells(string $stream): array
    {
        $strings = [];
        $rows = [];
        $sheet = 0; // 0 = workbook globals, 1 = the first sheet
        $length = strlen($stream);

        for ($pos = 0; $pos + 4 <= $length;) {
            $id = $this->u16($stream, $pos);
            $size = $this->u16($stream, $pos + 2);
            $data = substr($stream, $pos + 4, $size);
            $pos += 4 + $size;

            if ($id === 0x0809) { // start of a section
                if ($this->u16($data, 0) !== 0x0600) {
                    throw new RuntimeException('This Excel file is older than Excel 97 and cannot be read.');
                }
                continue;
            }
            if ($id === 0x000A) { // end of a section
                if (++$sheet > 1) {
                    break;
                }
                continue;
            }
            if ($id === 0x002F) {
                throw new RuntimeException('This Excel file is password-protected.');
            }

            if ($id === 0x00FC) { // shared strings, continued over the records that follow
                $chunks = [$data];
                while ($pos + 4 <= $length && $this->u16($stream, $pos) === 0x003C) {
                    $size = $this->u16($stream, $pos + 2);
                    $chunks[] = substr($stream, $pos + 4, $size);
                    $pos += 4 + $size;
                }
                $strings = $this->sharedStrings($chunks);
                continue;
            }
            if ($sheet !== 1 || strlen($data) < 6) {
                continue;
            }

            $row = $this->u16($data, 0);
            $column = $this->u16($data, 2);
            switch ($id) {
                case 0x00FD: // text held in the shared strings
                    $rows[$row][$column] = $strings[$this->u32($data, 6)] ?? '';
                    break;
                case 0x0204: // text held in the cell
                    $chunks = [substr($data, 6)];
                    $chunk = 0;
                    $at = 0;
                    $rows[$row][$column] = $this->string($chunks, $chunk, $at);
                    break;
                case 0x0203: // number
                    $rows[$row][$column] = $this->number(unpack('e', $data, 6)[1]);
                    break;
                case 0x027E: // compact number
                    $rows[$row][$column] = $this->number($this->compactNumber($this->u32($data, 6)));
                    break;
                case 0x00BD: // a run of compact numbers
                    for ($at = 4; $at + 6 <= strlen($data) - 2; $at += 6, $column++) {
                        $rows[$row][$column] = $this->number($this->compactNumber($this->u32($data, $at + 2)));
                    }
                    break;
            }
        }

        ksort($rows);

        return array_map(function (array $row) {
            ksort($row);

            return $row;
        }, $rows);
    }

    private function sharedStrings(array $chunks): array
    {
        $count = $this->u32($chunks[0], 4);
        $chunk = 0;
        $at = 8;
        $strings = [];
        for ($i = 0; $i < $count && $chunk < count($chunks); $i++) {
            // A string's header is never split; one that would not fit starts in the next record.
            if ($at >= strlen($chunks[$chunk])) {
                $chunk++;
                $at = 0;
                if ($chunk >= count($chunks)) {
                    break;
                }
            }
            $strings[] = $this->string($chunks, $chunk, $at);
        }

        return $strings;
    }

    /** One string at $chunks[$chunk][$at]; moves both past it. Text may run on into later chunks. */
    private function string(array $chunks, int &$chunk, int &$at): string
    {
        $data = $chunks[$chunk];
        $characters = $this->u16($data, $at);
        $flags = ord($data[$at + 2] ?? "\0");
        $at += 3;
        $runs = 0;
        $extra = 0;
        if ($flags & 0x08) {
            $runs = $this->u16($data, $at);
            $at += 2;
        }
        if ($flags & 0x04) {
            $extra = $this->u32($data, $at);
            $at += 4;
        }
        $wide = (bool) ($flags & 0x01);

        $text = '';
        while ($characters > 0) {
            $available = strlen($chunks[$chunk]) - $at;
            if ($available <= 0) {
                // The rest is in the next record, which says again whether it is one or two bytes a character.
                if (++$chunk >= count($chunks)) {
                    break;
                }
                $wide = (bool) (ord($chunks[$chunk][0] ?? "\0") & 0x01);
                $at = 1;
                continue;
            }
            $take = min($characters, $wide ? intdiv($available, 2) : $available);
            if ($take === 0) {
                $at = strlen($chunks[$chunk]);
                continue;
            }
            $bytes = substr($chunks[$chunk], $at, $take * ($wide ? 2 : 1));
            $text .= mb_convert_encoding($bytes, 'UTF-8', $wide ? 'UTF-16LE' : 'ISO-8859-1');
            $at += strlen($bytes);
            $characters -= $take;
        }

        // Formatting runs and phonetic data follow the text; skip them, across records if need be.
        $skip = $runs * 4 + $extra;
        while ($skip > 0 && $chunk < count($chunks)) {
            $step = min($skip, strlen($chunks[$chunk]) - $at);
            $at += $step;
            $skip -= $step;
            if ($skip > 0) {
                $chunk++;
                $at = 0;
            }
        }

        return $text;
    }

    private function compactNumber(int $rk): float
    {
        $value = $rk & 0x02
            ? (float) (($rk & 0x80000000 ? $rk - 0x100000000 : $rk) >> 2)
            : unpack('e', "\0\0\0\0".pack('V', $rk & 0xFFFFFFFC))[1];

        return $rk & 0x01 ? $value / 100 : $value;
    }

    private function number(float $value): string
    {
        return floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
    }

    private function u16(string $data, int $offset): int
    {
        return strlen($data) >= $offset + 2 ? unpack('v', $data, $offset)[1] : 0;
    }

    private function u32(string $data, int $offset): int
    {
        return strlen($data) >= $offset + 4 ? unpack('V', $data, $offset)[1] : 0xFFFFFFFF;
    }
}
