<?php

namespace App\Support\Clinical;

use App\Models\Refractions;
use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * The A5 slips a patient takes away: the spectacle prescription (for the optical lab) and the drug
 * prescription (for the pharmacy). Printed on A5, or two copies side by side on one A4 sheet
 * (Settings → Receipts & Payments → Prescription slips).
 */
class Slip
{
    public const A5 = 'a5';
    public const A4_TWO = 'a4_two';
    public const PAPERS = [self::A5 => 'A5 (one slip per sheet)', self::A4_TWO => 'A4, two copies side by side (patient and clinic)'];

    /** A spectacle prescription is good for two years (one for children); a drug prescription for 30 days. */
    public static function spectaclesValidUntil(CarbonInterface $examined, ?int $age): CarbonInterface
    {
        return $examined->copy()->addYears($age !== null && $age < 16 ? 1 : 2);
    }

    public static function drugsValidUntil(CarbonInterface $prescribed): CarbonInterface
    {
        return $prescribed->copy()->addDays(30);
    }

    public static function paper(?Setting $settings = null): string
    {
        $paper = ($settings ?? Setting::getSettings())?->prescription_paper;

        return array_key_exists((string) $paper, self::PAPERS) ? $paper : self::A5;
    }

    /** dompdf paper and orientation for the chosen layout. */
    public static function dompdfPaper(string $paper): array
    {
        return $paper === self::A4_TWO ? ['a4', 'landscape'] : ['a5', 'portrait'];
    }

    /** "RIGHT", "LEFT" or "BOTH" from the ways the eye is recorded ("OD (Right Eye)", "Both Eyes", "OU"...). */
    public static function eye(?string $value): ?string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') return null;
        if (str_contains($v, 'both') || str_contains($v, 'ou')) return 'BOTH';
        if (str_contains($v, 'right') || str_starts_with($v, 'od') || $v === 're') return 'RIGHT';
        if (str_contains($v, 'left') || str_starts_with($v, 'os') || $v === 'le') return 'LEFT';

        return strtoupper($value);
    }

    /**
     * One eye's spectacle prescription, from the structured fields, or the free text of an older record.
     *
     * @return array{sph: ?string, cyl: ?string, axis: ?string, add: ?string, va: ?string, near: ?string, text: ?string}
     */
    public static function rx(Refractions $r, string $eye): array
    {
        $e = $eye === 'os' ? 'os' : 'od';
        $E = strtoupper($e);
        $structured = $r->hasStructuredSubjective();
        $filled = fn ($v) => $v !== null && trim((string) $v) !== '';
        $add = $r->{"subjective_{$e}_add"};

        return [
            'sph' => $structured ? (Refractions::formatPower($r->{"subjective_{$e}_sphere"}) ?? (VisitCard::filled($r->{"subjective_{$e}_cylinder"}) ? 'Plano' : null)) : null,
            'cyl' => $structured ? Refractions::formatPower($r->{"subjective_{$e}_cylinder"}) : null,
            'axis' => $structured && $filled($r->{"subjective_{$e}_axis"}) ? str_pad((string) $r->{"subjective_{$e}_axis"}, 3, '0', STR_PAD_LEFT).'°' : null,
            'add' => $filled($add) ? (is_numeric($add) ? Refractions::formatPower($add) : $add) : ($r->{"refraction{$E}_ADD"} ?: null),
            'va' => $r->{"subjective_{$e}_bcva"} ?: ($r->{"refraction{$E}_distance_va"} ?: null),
            'near' => $r->{"refraction{$E}_near_va"} ?: null,
            'text' => $structured ? null : ($r->{"refraction{$E}"} ?: null),
        ];
    }

    /**
     * Lens design and coatings read from the recorded lens type ("Progressive Blue Block Photo").
     *
     * @return array{design: array<string, bool>, coatings: array<string, bool>}
     */
    public static function lens(?string $lensType): array
    {
        $t = ' '.strtolower((string) $lensType).' ';
        $has = fn (array $words) => collect($words)->contains(fn ($w) => str_contains($t, $w));
        $progressive = $has(['progressive', ' pal ', 'varifocal']);
        $bifocal = $has(['bifocal', ' kt ', 'd-seg', 'flat top']);

        return [
            'design' => [
                'Single vision' => ! $progressive && ! $bifocal && $has([' sv ', 'single vision', 'single']),
                'Bifocal' => $bifocal,
                'Progressive' => $progressive,
            ],
            'coatings' => [
                'Anti-reflective' => $has([' ar ', 'anti-reflect', 'anti reflect']),
                'Blue filter' => $has(['blue']),
                'Photochromic' => $has(['photo', 'transition']),
            ],
        ];
    }
}
