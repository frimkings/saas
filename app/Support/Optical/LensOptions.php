<?php

namespace App\Support\Optical;

use App\Models\OpticalLensOption;
use App\Models\OpticalProduct;
use Illuminate\Support\Collection;

/**
 * The lens designs and treatments a clinic offers when receiving and ordering lenses.
 *
 * Stock keeps each option's code; staff see its name. Designs are the three the stock
 * matching is built on (single vision on CYL; bifocal and progressive per eye on ADD), so
 * they can be renamed, hidden and reordered but not added. Treatments are the clinic's own
 * list. A clinic starts with the defaults below plus any treatment already on its stock.
 */
class LensOptions
{
    public const DESIGNS = ['Single Vision' => 'Single Vision', 'Bifocal' => 'Bifocal', 'Progressive' => 'Progressive'];

    public const TREATMENTS = [
        'AR' => 'Clear AR', 'Photo AR' => 'Photo AR', 'Photo Gray' => 'Photo Gray', 'Photochromic' => 'Photochromic',
        'Blue AR' => 'Blue AR', 'BlueCut' => 'Blue cut', 'Transitions' => 'Transitions', 'HC' => 'Hard coat',
    ];

    /** @return Collection<int, OpticalLensOption> every option of a kind, in display order */
    public static function all(string $kind): Collection
    {
        $cache = self::cache();
        $key = OpticalLensOption::clinicIdForWrite().':'.$kind;
        return $cache[$key] ??= self::load($kind);
    }

    /** Per clinic and kind, for this request only (the container is rebuilt per request). */
    private static function cache(): \ArrayObject
    {
        if (! app()->bound('optical.lens-options')) app()->instance('optical.lens-options', new \ArrayObject());
        return app('optical.lens-options');
    }

    /** @return array<string, string> code => name of the options staff can choose */
    public static function choices(string $kind, ?string $keep = null): array
    {
        return self::all($kind)->filter(fn ($option) => $option->is_active || $option->code === $keep)
            ->mapWithKeys(fn ($option) => [$option->code => $option->name])->all();
    }

    /** The name staff see for a stored code; unknown codes show as they are. */
    public static function label(string $kind, ?string $code): string
    {
        $code = (string) $code;
        return (string) (self::all($kind)->firstWhere('code', $code)?->name ?? $code);
    }

    public static function forget(): void
    {
        app()->instance('optical.lens-options', new \ArrayObject());
    }

    private static function load(string $kind): Collection
    {
        $options = OpticalLensOption::where('kind', $kind)->orderBy('sort_order')->orderBy('id')->get();
        if ($options->isNotEmpty() || ! OpticalLensOption::clinicIdForWrite()) return $options;

        // First use: the defaults, then treatments already on this clinic's stock.
        $defaults = $kind === 'design' ? self::DESIGNS : self::TREATMENTS;
        if ($kind === 'treatment') {
            OpticalProduct::whereNotNull('lens_specs')->pluck('lens_specs')
                ->map(fn ($specs) => trim((string) data_get($specs, 'coating')))->filter()->unique()
                ->each(function ($code) use (&$defaults) { $defaults[$code] ??= $code; });
        }
        $order = 0;
        foreach ($defaults as $code => $name) {
            OpticalLensOption::firstOrCreate(['kind' => $kind, 'code' => $code], ['name' => $name, 'sort_order' => ++$order, 'is_active' => true]);
        }
        return OpticalLensOption::where('kind', $kind)->orderBy('sort_order')->orderBy('id')->get();
    }
}
