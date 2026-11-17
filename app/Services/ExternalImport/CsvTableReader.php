<?php

namespace App\Services\ExternalImport;

use RuntimeException;

/** Reads a .csv file as a table, like XlsxTableReader: row 1 is the header, every later row becomes header => value. */
class CsvTableReader
{
    private const MAX_ROWS = 200000;

    public function read(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) throw new RuntimeException('The .csv file could not be opened.');
        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (! $headers) throw new RuntimeException('The .csv file is empty.');
            $headers[0] = preg_replace('/^\x{FEFF}/u', '', (string) $headers[0]);
            $headers = array_map(fn ($h) => trim((string) $h), $headers);
            $rows = [];
            while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($line === [null] || ! array_filter($line, fn ($v) => trim((string) $v) !== '')) continue;
                if (count($rows) >= self::MAX_ROWS) throw new RuntimeException('A file may have at most '.number_format(self::MAX_ROWS).' rows.');
                $row = [];
                foreach ($headers as $i => $header) if ($header !== '') $row[$header] = trim((string) ($line[$i] ?? ''));
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return ['headers' => array_values(array_filter($headers, fn ($h) => $h !== '')), 'rows' => $rows];
    }
}
