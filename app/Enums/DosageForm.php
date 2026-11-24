<?php

namespace App\Enums;

/**
 * What form a drug comes in (24 Nov 2026). It decides what a prescription line asks for: an eye for drops and
 * ointments, a dose and meal timing for tablets, and how many strips to dispense for tablets and capsules.
 */
enum DosageForm: string
{
    case EYE_DROPS = 'eye_drops';
    case EYE_OINTMENT = 'eye_ointment';
    case EYE_GEL = 'eye_gel';
    case TABLET = 'tablet';
    case CAPSULE = 'capsule';
    case SYRUP = 'syrup';
    case INJECTION = 'injection';
    case CREAM = 'cream';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EYE_DROPS => 'Eye drops',
            self::EYE_OINTMENT => 'Eye ointment',
            self::EYE_GEL => 'Eye gel',
            self::TABLET => 'Tablet',
            self::CAPSULE => 'Capsule',
            self::SYRUP => 'Syrup / suspension',
            self::INJECTION => 'Injection',
            self::CREAM => 'Cream (skin)',
            self::OTHER => 'Other',
        };
    }

    /** @return array<string, string> value => label, for dropdowns. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $f) => [$f->value => $f->label()])->all();
    }

    /**
     * Whether a prescription line asks which eye: 'required' for drops, ointments and gels, 'optional' for an
     * injection (it may be given into the eye) or "other", 'none' for drugs taken by mouth or put on the skin.
     * A drug with no form set keeps the old rule: the eye is required.
     */
    public static function eyeMode(?self $form): string
    {
        return match ($form) {
            null, self::EYE_DROPS, self::EYE_OINTMENT, self::EYE_GEL => 'required',
            self::INJECTION, self::OTHER => 'optional',
            default => 'none',
        };
    }

    /** How the drug is taken, shown in place of the eye when no eye is asked. */
    public function route(): ?string
    {
        return match ($this) {
            self::TABLET, self::CAPSULE, self::SYRUP => 'By mouth',
            self::CREAM => 'On the skin',
            default => null,
        };
    }

    /** Tablets and capsules are sold by the strip: the line works out how many strips cover the course. */
    public function soldByStrip(): bool
    {
        return in_array($this, [self::TABLET, self::CAPSULE], true);
    }

    /** Whether the line asks for a dose (how much each time). */
    public function asksDose(): bool
    {
        return in_array($this, [self::TABLET, self::CAPSULE, self::SYRUP, self::INJECTION], true);
    }

    /** Whether the line asks when to take it with respect to meals. */
    public function asksMealTiming(): bool
    {
        return in_array($this, [self::TABLET, self::CAPSULE, self::SYRUP], true);
    }

    /** The dose a new line starts with. */
    public function defaultDose(): ?float
    {
        return match ($this) {
            self::TABLET, self::CAPSULE => 1.0,
            self::SYRUP => 5.0,
            default => null,
        };
    }

    /** "1 tablet", "2 tablets", "½ tablet", "5 ml". */
    public function doseLabel(float|string|null $dose): ?string
    {
        if ($dose === null || $dose === '' || (float) $dose <= 0) return null;
        $dose = (float) $dose;
        $amount = match (true) {
            abs($dose - 0.5) < 0.001 => '½',
            abs($dose - 0.25) < 0.001 => '¼',
            abs($dose - 1.5) < 0.001 => '1½',
            default => rtrim(rtrim(number_format($dose, 2, '.', ''), '0'), '.'),
        };

        return match ($this) {
            self::TABLET => $amount . ' ' . ($dose > 1 ? 'tablets' : 'tablet'),
            self::CAPSULE => $amount . ' ' . ($dose > 1 ? 'capsules' : 'capsule'),
            self::SYRUP, self::INJECTION => $amount . ' ml',
            self::EYE_DROPS => $amount . ' ' . ($dose > 1 ? 'drops' : 'drop'),
            default => $amount,
        };
    }

    /** "tablets" / "capsules": what a strip holds. */
    public function unitPlural(): string
    {
        return $this === self::CAPSULE ? 'capsules' : 'tablets';
    }

    /**
     * A first guess from the product's name, or null when the name does not say. Order matters: "ophthalmic
     * suspension" is drops, not syrup; "Oc" (oculentum) is an ointment; a bare strength in mg with no % or ml
     * is taken as a tablet, and a strength in % as eye drops.
     */
    public static function guess(?string $name): ?self
    {
        $n = ' ' . mb_strtolower((string) $name) . ' ';
        if (trim($n) === '') return null;

        $rules = [
            [self::EYE_DROPS, '/\b(eye ?drops?|drops?|gtts?|e\/d|tears|ophth?almic (solution|soln|susp|suspension|emulsion))\b/u'],
            [self::EYE_OINTMENT, '/\b(eye ?oint(ment)?|oint(ment)?|ung|e\/o|oc)\b/u'],
            [self::EYE_GEL, '/\bgel\b/u'],
            [self::INJECTION, '/\b(inj|injection|amp|ampoule|ampule|vial)\b/u'],
            [self::SYRUP, '/\b(syr|syrup|susp|suspension|elixir|mixture|oral solution)\b/u'],
            [self::CAPSULE, '/\b(caps?|capsules?)\b/u'],
            [self::TABLET, '/\b(tabs?|tablets?|tbs)\b/u'],
            [self::CREAM, '/\b(cream|lotion)\b/u'],
        ];
        foreach ($rules as [$form, $pattern]) {
            if (preg_match($pattern, $n)) return $form;
        }

        if (preg_match('/\d\s*mg\b/u', $n) && !str_contains($n, '%') && !preg_match('/\d\s*ml\b/u', $n)) return self::TABLET;
        // An eye clinic's drug with a strength in % ("Ciprofloxacin 0.3%") is almost always drops.
        if (preg_match('/\d\s*%/u', $n) || preg_match('/\bophth?almic\b/u', $n)) return self::EYE_DROPS;

        return null;
    }
}
