<?php

namespace App\Support\Optical;

use App\Models\OpticalLensOption;
use App\Models\OpticalProduct;
use Illuminate\Support\Collection;

/**
 * The lens choices a clinic offers when receiving and ordering lenses, as its staff name
 * them: lens types (designs), forms, treatments and manufacturers.
 *
 * Stock keeps each option's code; staff see its name, so renaming never splits stock.
 * Lens types are the three the stock matching is built on (single vision on CYL; bifocal
 * and progressive per eye on ADD): they can be renamed, hidden and reordered, not added.
 * Forms belong to a lens type (flat top and invisible to bifocal, wider corridor to
 * progressive); "Standard" is every lens type's plain form and is not written on stock.
 * Treatments and manufacturers are the clinic's own lists. A clinic starts with the
 * defaults below plus whatever its stock already uses.
 */
class LensOptions
{
    public const DESIGNS = ['Single Vision' => 'Single Vision', 'Bifocal' => 'Bifocal', 'Progressive' => 'Progressive'];

    public const TREATMENTS = [
        'AR' => 'Clear AR', 'Photo AR' => 'Photo AR', 'Photo Gray' => 'Photo Gray', 'Photochromic' => 'Photochromic',
        'Blue AR' => 'Blue AR', 'BlueCut' => 'Blue cut', 'Transitions' => 'Transitions', 'HC' => 'Hard coat',
    ];

    /** The plain form of every lens type; lenses without a form on their stock record are Standard. */
    public const STANDARD_FORM = 'Standard';

    /** code => lens type it belongs to (null = every lens type) */
    public const FORMS = [self::STANDARD_FORM => null, 'Flat top' => 'Bifocal', 'Invisible' => 'Bifocal', 'Wider corridor' => 'Progressive'];

    /** Which lens_specs field each kind is stored in on stock. */
    public const SPEC_FIELDS = ['design' => 'design', 'form' => 'form', 'treatment' => 'coating', 'manufacturer' => 'range'];

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

    /**
     * @return array<string, string> code => name of the options staff can choose; forms can be
     *                               narrowed to one lens type, and a code already chosen is kept
     */
    public static function choices(string $kind, ?string $keep = null, ?string $design = null): array
    {
        return self::all($kind)
            ->filter(fn ($option) => $option->is_active || $option->code === $keep)
            ->filter(fn ($option) => $kind !== 'form' || $design === null || $option->design === null || $option->design === $design || $option->code === $keep)
            ->mapWithKeys(fn ($option) => [$option->code => $option->name])->all();
    }

    /** The name staff see for a stored code; unknown codes show as they are, and no form is Standard. */
    public static function label(string $kind, ?string $code): string
    {
        $code = (string) $code;
        if ($kind === 'form' && $code === '') $code = self::STANDARD_FORM;
        return (string) (self::all($kind)->firstWhere('code', $code)?->name ?? $code);
    }

    /** The form written on stock for a chosen form: none for Standard, so existing stock keeps its identity. */
    public static function storedForm(?string $form): ?string
    {
        $form = trim((string) $form);
        return $form === '' || $form === self::STANDARD_FORM ? null : $form;
    }

    public static function forget(): void
    {
        app()->instance('optical.lens-options', new \ArrayObject());
    }

    private static function load(string $kind): Collection
    {
        $options = OpticalLensOption::where('kind', $kind)->orderBy('sort_order')->orderBy('id')->get();
        if ($options->isNotEmpty() || ! OpticalLensOption::clinicIdForWrite()) return $options;

        // First use: the defaults, then what this clinic's stock already uses.
        $defaults = match ($kind) {
            'design' => array_map(fn ($name) => [$name, null], self::DESIGNS),
            'treatment' => array_map(fn ($name) => [$name, null], self::TREATMENTS),
            'form' => array_map(fn ($design) => [null, $design], self::FORMS),
            default => [],
        };
        if ($kind !== 'design') {
            $field = self::SPEC_FIELDS[$kind];
            OpticalProduct::withTrashed()->whereNotNull('lens_specs')->pluck('lens_specs')
                ->map(fn ($specs) => trim((string) data_get($specs, $field)))->filter()->unique()->sort()
                ->each(function ($code) use (&$defaults) { $defaults[$code] ??= [$code, null]; });
        }
        $order = 0;
        foreach ($defaults as $code => [$name, $design]) {
            OpticalLensOption::firstOrCreate(['kind' => $kind, 'code' => (string) $code],
                ['name' => $name ?? (string) $code, 'design' => $design, 'sort_order' => ++$order, 'is_active' => true]);
        }
        return OpticalLensOption::where('kind', $kind)->orderBy('sort_order')->orderBy('id')->get();
    }
}
