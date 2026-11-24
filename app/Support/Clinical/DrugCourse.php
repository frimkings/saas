<?php

namespace App\Support\Clinical;

use App\Enums\DosageForm;
use App\Enums\MealTiming;
use App\Enums\ProductFrequency;

/**
 * A prescribed drug's course from its line (24 Nov 2026): how many tablets it needs, how many strips that is,
 * and the directions read out to the patient. Works on a line array (doctor's screen) or a model (cart, sale
 * item, outside prescription) with the same field names.
 */
final class DrugCourse
{
    public static function form(mixed $line): ?DosageForm
    {
        $value = self::get($line, 'dosage_form');

        return $value instanceof DosageForm ? $value : DosageForm::tryFrom((string) $value);
    }

    /** Days the course lasts, or null for "until finished" or an unset duration. */
    public static function days(mixed $line): ?int
    {
        $value = (int) self::get($line, 'duration_value');
        if ($value < 1) return null;

        return match (self::get($line, 'duration_unit')) {
            'days' => $value,
            'weeks' => $value * 7,
            'months' => $value * 30,
            default => null,
        };
    }

    /** Tablets or capsules the whole course takes, or null when it cannot be counted. */
    public static function units(mixed $line): ?int
    {
        $form = self::form($line);
        $dose = (float) self::get($line, 'dose');
        if (!$form?->soldByStrip() || $dose <= 0) return null;

        $frequency = self::get($line, 'frequency');
        $frequency = $frequency instanceof ProductFrequency ? $frequency : ProductFrequency::tryFrom((string) $frequency);
        if ($frequency === ProductFrequency::SINGLE_DOSE) return (int) ceil($dose);

        $perDay = $frequency?->timesPerDay();
        $days = self::days($line);
        if (!$perDay || !$days) return null;

        return (int) ceil(round($dose * $perDay * $days, 4));
    }

    /** Strips to dispense for the course, or null when the course or the strip size is unknown. */
    public static function strips(mixed $line, ?int $packSize): ?int
    {
        $units = self::units($line);
        if (!$units || !$packSize || $packSize < 1) return null;

        return (int) ceil($units / $packSize);
    }

    /** "1 tablet · Four Times Daily · after meals · 5 days", with the eye for eye drugs. */
    public static function directions(mixed $line, bool $withEye = true): string
    {
        $form = self::form($line);
        $frequency = self::get($line, 'frequency');
        $frequency = $frequency instanceof ProductFrequency ? $frequency->value : $frequency;
        $meal = MealTiming::tryFrom((string) self::get($line, 'meal_timing'));
        $unit = self::get($line, 'duration_unit');
        $duration = $unit === 'until_finished' ? 'until finished'
            : ((int) self::get($line, 'duration_value') ? (int) self::get($line, 'duration_value') . ' ' . ($unit ?: 'days') : null);
        $eye = !$withEye || DosageForm::eyeMode($form) === 'none' ? null : self::get($line, 'eye');

        return collect([
            $form?->doseLabel(self::get($line, 'dose')),
            $eye,
            $frequency ?: null,
            $meal ? lcfirst($meal->label()) : null,
            $duration,
        ])->filter(fn ($part) => filled($part))->implode(' · ');
    }

    private static function get(mixed $line, string $key): mixed
    {
        return is_array($line) ? ($line[$key] ?? null) : ($line->{$key} ?? null);
    }
}
