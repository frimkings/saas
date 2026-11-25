<?php

namespace App\Support\Clinical;

use App\Models\Consultations;
use Illuminate\Support\Collection;

/**
 * What one visit's card shows on the clinical documents (visit summary, consultation report,
 * medical record): measurements always, abnormal findings eye by eye, and normal ones folded
 * into a single line, so a routine visit takes a few lines instead of a page.
 */
class VisitCard
{
    /** Always listed eye by eye when recorded. */
    public const MEASURES = [
        'VA unaided' => ['vaOD6m', 'vaOS6m'],
        'VA pinhole' => ['vaODph', 'vaOSph'],
        'VA with glasses' => ['vaODcc', 'vaOScc'],
        'IOP' => ['IOPOD', 'IOPOS'],
        'C/D ratio' => ['cdrOD', 'cdrOS'],
    ];

    /** Listed eye by eye only when something is not normal; otherwise named in the "normal" line. */
    public const STRUCTURES = [
        'Lids' => ['lidsOD', 'lidsOS'],
        'Conjunctiva' => ['conjunctivaOD', 'conjunctivaOS'],
        'Cornea' => ['corneaOD', 'corneaOS'],
        'AC' => ['acOD', 'acOS'],
        'Iris' => ['irisOD', 'irisOS'],
        'Pupil' => ['pupilOD', 'pupilOS'],
        'Lens' => ['lensOD', 'lensOS'],
        'Vitreous' => ['vitreousOD', 'vitreousOS'],
        'Fundus' => ['fundusOD', 'fundusOS'],
    ];

    /** Findings the consultation screen offers as the normal choice for each structure. */
    private const NORMAL = [
        'normal', 'clear', 'white and quiet', 'deep and quiet', 'normal pattern', 'round, regular and reactive',
        'quiet', 'healthy', 'nad', 'wnl', 'within normal limits', 'no abnormality detected', 'perrla', 'flat', 'intact',
    ];

    public const IOP_HIGH = 21;

    public static function filled($value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    public static function isNormal($value): bool
    {
        return self::filled($value) && in_array(strtolower(trim((string) $value)), self::NORMAL, true);
    }

    /**
     * @return array{rows: array<string, array{0: ?string, 1: ?string}>, normal: list<string>}
     *   rows: label => [OD, OS] to print; normal: structures that are normal in both eyes (or the one recorded).
     */
    public static function exam(Consultations $c): array
    {
        $rows = [];
        foreach (self::MEASURES as $label => [$od, $os]) {
            if (self::filled($c->$od) || self::filled($c->$os)) $rows[$label] = [$c->$od, $c->$os];
        }
        $normal = [];
        foreach (self::STRUCTURES as $label => [$od, $os]) {
            $a = $c->$od;
            $b = $c->$os;
            if (! self::filled($a) && ! self::filled($b)) continue;
            $allNormal = (! self::filled($a) || self::isNormal($a)) && (! self::filled($b) || self::isNormal($b));
            if ($allNormal) $normal[] = $label;
            else $rows[$label] = [$a, $b];
        }

        return ['rows' => $rows, 'normal' => $normal];
    }

    public static function iopHigh($value): bool
    {
        return self::filled($value) && is_numeric($value) && (float) $value > self::IOP_HIGH;
    }

    /** "Headache (OU, ++); Discharge (OU, +)". */
    public static function symptoms(Consultations $c): string
    {
        $list = collect($c->odq_details ?: [])->values();
        if ($list->isEmpty()) $list = collect($c->odq ?: [])->map(fn ($s) => ['symptom' => $s]);

        return $list->map(fn ($s) => trim(($s['symptom'] ?? '').(! empty($s['eye']) ? ' ('.$s['eye'].(! empty($s['severity']) ? ', '.$s['severity'] : '').')' : '')))
            ->filter()->implode('; ');
    }

    /**
     * Prescribed items: medicines as one line each, other items (lenses, frames) as a list.
     *
     * @return array{medicines: list<string>, items: list<string>}
     */
    public static function prescribed(Consultations $c): array
    {
        $medicines = [];
        $items = [];
        foreach ($c->relationLoaded('cartItems') ? $c->cartItems : collect() as $item) {
            $name = trim((string) ($item->product->name ?? '')) ?: 'Item';
            if (self::filled($item->frequency) || self::filled($item->eye) || self::filled($item->duration_unit)) {
                $form = DrugCourse::form($item) ?? $item->product?->dosage_form;
                $quantity = $name.' ×'.$item->quantity.($form?->soldByStrip() ? ' '.((int) $item->quantity === 1 ? 'strip' : 'strips') : '');
                $medicines[] = collect([$quantity, strtolower(DrugCourse::directions($item)) ?: null,
                    DrugInstructions::line($item->instructions, $item->taper)])
                    ->filter(fn ($p) => self::filled($p))->implode(' — ');
            } else {
                $items[] = $name.($item->quantity > 1 ? ' ×'.$item->quantity : '');
            }
        }

        return ['medicines' => $medicines, 'items' => $items];
    }

    /** The branch a visit was at, for a clinic with more than one branch (21 Nov 2026: one chart across branches). */
    public static function branchName(Consultations $c): ?string
    {
        static $counts = [];
        $clinicId = (int) $c->clinic_id;
        $counts[$clinicId] ??= \App\Models\Branch::withoutGlobalScopes()->where('clinic_id', $clinicId)->count();
        if ($counts[$clinicId] < 2 || ! $c->branch_id) return null;

        return \App\Models\Branch::withoutGlobalScopes()->whereKey($c->branch_id)->value('name');
    }

    /** One row per visit for the record's timeline table, oldest values first is up to the caller. */
    public static function timelineRow(Consultations $c): array
    {
        $rx = $c->relationLoaded('refraction') ? $c->refraction : null;

        return [
            'date' => $c->created_at,
            'branch' => self::branchName($c),
            'complaint' => $c->chiefComplaint,
            'va' => [$c->vaOD6m, $c->vaOS6m],
            'iop' => [$c->IOPOD, $c->IOPOS],
            'cdr' => [$c->cdrOD, $c->cdrOS],
            'diagnoses' => $c->relationLoaded('diagnoses') ? $c->diagnoses->pluck('name')->implode(', ') : '',
            'rx' => $rx ? array_filter([$rx->subjectiveRx('od'), $rx->subjectiveRx('os')]) : [],
        ];
    }

    /** Diagnoses ever recorded for these visits, most recent first, each once. */
    public static function conditions(Collection $visits): Collection
    {
        return $visits->flatMap(fn ($c) => $c->relationLoaded('diagnoses') ? $c->diagnoses->pluck('name') : [])->unique()->values();
    }
}
