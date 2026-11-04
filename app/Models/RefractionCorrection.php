<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** A change to a saved refraction, with its reason and the values before and after (changed fields only). */
class RefractionCorrection extends Model
{
    use BelongsToClinic;

    protected $guarded = ['id', 'clinic_id'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array'];

    /** Names for the fields a correction lists; anything else is shown by its column name. */
    public const LABELS = [
        'subjective_od_sphere' => 'OD sphere', 'subjective_od_cylinder' => 'OD cylinder', 'subjective_od_axis' => 'OD axis',
        'subjective_od_add' => 'OD ADD', 'subjective_od_bcva' => 'OD BCVA', 'refractionOD_near_va' => 'OD near VA',
        'subjective_os_sphere' => 'OS sphere', 'subjective_os_cylinder' => 'OS cylinder', 'subjective_os_axis' => 'OS axis',
        'subjective_os_add' => 'OS ADD', 'subjective_os_bcva' => 'OS BCVA', 'refractionOS_near_va' => 'OS near VA',
        'objective_method' => 'Objective method',
        'objective_od_sphere' => 'Objective OD sphere', 'objective_od_cylinder' => 'Objective OD cylinder', 'objective_od_axis' => 'Objective OD axis', 'objective_od_va' => 'Objective OD VA',
        'objective_os_sphere' => 'Objective OS sphere', 'objective_os_cylinder' => 'Objective OS cylinder', 'objective_os_axis' => 'Objective OS axis', 'objective_os_va' => 'Objective OS VA',
        'objective_notes' => 'Objective notes', 'subjective_notes' => 'Subjective notes', 'refractionnotes' => 'Notes',
        'dispensing_required' => 'Glasses to be dispensed', 'pd' => 'P.D.', 'lensType' => 'Lens type',
    ];

    public function refraction() { return $this->belongsTo(Refractions::class, 'refraction_id'); }
    public function user() { return $this->belongsTo(User::class); }

    /** ["OD sphere: -1.00 → -1.25", ...] */
    public function changes(): array
    {
        $shown = fn ($v) => $v === null || $v === '' ? '—' : (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v);

        return collect($this->new_values)->map(fn ($new, $field) => (self::LABELS[$field] ?? $field) . ': '
            . $shown($this->old_values[$field] ?? null) . ' → ' . $shown($new))->values()->all();
    }
}
