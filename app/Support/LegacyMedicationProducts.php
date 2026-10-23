<?php

namespace App\Support;

use App\Models\LegacyMedicationMatch;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which product a drug name from the previous software stands for: the match the clinic set
 * (LegacyMedicationMatch), otherwise a product with the same name, ignoring case and spaces at the ends.
 */
class LegacyMedicationProducts
{
    public static function key(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }

    /** @return array<string, int> name key => product id, only for names that have a product */
    public static function forNames(iterable $names): array
    {
        $keys = collect($names)->map(fn ($n) => self::key($n))->filter()->unique()->values();
        if ($keys->isEmpty()) return [];

        $matched = LegacyMedicationMatch::whereIn('name_key', $keys)->whereHas('product')->pluck('product_id', 'name_key')->map(fn ($id) => (int) $id)->all();

        return $matched + self::sameName($keys->diff(array_keys($matched)));
    }

    /** Products named exactly like the old drug, preferring one in stock. */
    public static function sameName(Collection $keys): array
    {
        if ($keys->isEmpty()) return [];

        return Product::whereIn(DB::raw('LOWER(TRIM(name))'), $keys->values())
            ->orderByRaw('quantity > 0 desc')->orderBy('id')->get(['id', 'name'])
            ->unique(fn ($p) => self::key($p->name))
            ->mapWithKeys(fn ($p) => [self::key($p->name) => (int) $p->id])->all();
    }
}
