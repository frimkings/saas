<?php

namespace App\Services\ExternalImport;

use App\Models\ExternalImportBatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What every vendor importer shares: writing rows so ExternalImportRollback can undo them, counting
 * what was skipped, and reading the dates, text, lens powers and diagnosis names old software keeps.
 */
trait ImportHelpers
{
    private ExternalImportBatch $batch;
    private array $tracked = [];
    private array $counts = [];
    private array $skipped = [];

    private function insert(string $table, array $row): int
    {
        $id = (int) DB::table($table)->insertGetId($row);
        $this->tracked[] = ['external_import_batch_id' => $this->batch->id, 'table_name' => $table, 'record_id' => $id];
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
        if (count($this->tracked) >= 500) $this->flush();

        return $id;
    }

    /** Writes the list of created rows; also called when an import fails so its partial rows can be undone. */
    public function flush(): void
    {
        foreach (array_chunk($this->tracked, 500) as $chunk) DB::table('external_import_rows')->insert($chunk);
        $this->tracked = [];
    }

    private function skip(string $sheet, string $reason): void
    {
        $this->skipped[$sheet][$reason] = ($this->skipped[$sheet][$reason] ?? 0) + 1;
    }

    /** Excel stores dates as day numbers since 1899-12-30; other exports write them as text. */
    public function dateTime($value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (is_numeric($value)) return (float) $value > 1 && (float) $value < 2958465 ? Carbon::create(1899, 12, 30, 0, 0, 0)->addSeconds((int) round((float) $value * 86400)) : null;
        try { return Carbon::parse($value); } catch (\Throwable) { return null; }
    }

    private function date($value): ?Carbon
    {
        return $this->dateTime($value)?->startOfDay();
    }

    private function widen(array &$range, Carbon $day): void
    {
        if (! $range[0] || $day->lt($range[0])) $range[0] = $day;
        if (! $range[1] || $day->gt($range[1])) $range[1] = $day;
    }

    private function text($value): ?string
    {
        $value = trim(preg_replace("/[ \t\x{00A0}]+/u", ' ', preg_replace("/\n{3,}/", "\n\n", (string) $value)));

        return $value === '' ? null : $value;
    }

    private function gender($value): string
    {
        return match (strtolower(trim((string) $value))) { 'm', 'male' => 'Male', 'f', 'female' => 'Female', 'other', 'o' => 'Other', default => '' };
    }

    private function power($value): ?float
    {
        $value = str_replace(['−', ' '], ['-', ''], trim((string) $value));
        if (in_array(strtolower($value), ['pl', 'plano', 'plan'], true)) return 0.0;

        return is_numeric($value) && abs((float) $value) < 100 ? round((float) $value, 2) : null;
    }

    /** "Cataract BE", "cataract (be)" and "Cataract" are one diagnosis; the eye stays in the visit notes. */
    private function withoutEye(string $name): string
    {
        $eye = '(BE|OU|RE|LE|OD|OS|B\/E|BOTH EYES|RIGHT EYE|LEFT EYE|LE\s*>\s*RE|RE\s*>\s*LE)';
        do {
            $before = $name;
            $name = trim(preg_replace('/[\s,;.]*\(?\s*'.$eye.'\s*\)?[\s.,;]*$/i', '', $name));
        } while ($name !== $before && $name !== '');

        return $name === '' ? trim($before) : $name;
    }

    private function diagnosisKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', $this->withoutEye($name)));
    }

    /** The clinic's diagnosis list keyed by diagnosisKey(). */
    private function clinicDiagnoses(int $clinicId): array
    {
        $map = [];
        foreach (DB::table('diagnoses')->where('clinic_id', $clinicId)->whereNull('deleted_at')->orderBy('id')->get(['id', 'name']) as $d) $map[$this->diagnosisKey($d->name)] ??= $d->id;

        return $map;
    }

    /** The diagnosis id for a name, adding it to the clinic list when allowed; null when it may not be added. */
    private function diagnosisId(string $name, array &$known, bool $add): ?int
    {
        $key = $this->diagnosisKey($name);
        if (isset($known[$key])) return $known[$key];
        if (! $add) return null;

        return $known[$key] = $this->insert('diagnoses', ['clinic_id' => $this->batch->clinic_id, 'name' => Str::limit(Str::ucfirst($this->withoutEye($name)), 250, ''), 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Every product here has a batch number (4-15 characters, unique in the clinic), a manufacture date and a
     * later expiry date: the Products page and the stock lists rely on them. Old software has none, so an imported
     * product gets batch <prefix>-<item id>, its creation date, and an expiry two years from the import,
     * to be replaced with real batches and dates at the stock count. $taken is updated with the batch used.
     */
    public static function productDefaults($itemId, Carbon $created, array &$taken, string $prefix = 'CLD'): array
    {
        $base = $prefix.'-'.Str::limit(preg_replace('/[^A-Za-z0-9]/', '', (string) $itemId) ?: Str::upper(Str::random(6)), 12 - strlen($prefix), '');
        $batch = $base;
        for ($n = 2; isset($taken[mb_strtolower($batch)]); $n++) $batch = Str::limit($base, 15 - strlen("-$n"), '')."-$n";
        $taken[mb_strtolower($batch)] = true;
        $made = $created->copy()->startOfDay()->min(today());

        return ['batch_number' => $batch, 'manufacture_date' => $made->toDateString(), 'expiry_date' => today()->addYears(2)->toDateString()];
    }

    /** The clinic's category id for a product type, created when the clinic has none. */
    private function categoryFor(string $type, int $ownerId, array &$cache): int
    {
        $names = ['drug' => 'Drugs', 'frame' => 'Frames', 'lens' => 'Lenses', 'service' => 'Services', 'product' => 'Others'];
        if (isset($cache[$type])) return $cache[$type];
        $all = DB::table('categories')->where('clinic_id', $this->batch->clinic_id)->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'type']);

        return $cache[$type] = $all->firstWhere('type', $type)?->id
            ?? $all->first(fn ($c) => mb_strtolower($c->name) === mb_strtolower($names[$type]))?->id
            ?? $this->insert('categories', ['clinic_id' => $this->batch->clinic_id, 'user_id' => $ownerId, 'name' => $names[$type], 'type' => $type, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
