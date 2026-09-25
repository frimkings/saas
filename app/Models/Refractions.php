<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToBranch;

class Refractions extends Model
{
    use HasFactory, BelongsToBranch;

    protected $fillable = [
        'consultation_id',
        'user_id',
        'objective_method',
        'objective_od_sphere',
        'objective_od_cylinder',
        'objective_od_axis',
        'objective_od_va',
        'objective_os_sphere',
        'objective_os_cylinder',
        'objective_os_axis',
        'objective_os_va',
        'objective_notes',
        'subjective_od_sphere',
        'subjective_od_cylinder',
        'subjective_od_axis',
        'subjective_od_add',
        'subjective_od_bcva',
        'subjective_os_sphere',
        'subjective_os_cylinder',
        'subjective_os_axis',
        'subjective_os_add',
        'subjective_os_bcva',
        'subjective_notes',
        'dispensing_required',
        'dispensing_authorized_by',
        'dispensing_authorized_at',
        'pd',
        'lensType',
        'refractionOD',
        'refractionOS',
        'refractionOD_distance_va',
        'refractionOD_ADD',
        'refractionOD_near_va',
        'refractionOS_distance_va',
        'refractionOS_ADD',
        'refractionOS_near_va',
        'refractionnotes',
    ];

    protected $casts = [
        'dispensing_required' => 'boolean',
        'dispensing_authorized_at' => 'datetime',
        'objective_od_sphere' => 'decimal:2',
        'objective_od_cylinder' => 'decimal:2',
        'objective_od_axis' => 'integer',
        'objective_os_sphere' => 'decimal:2',
        'objective_os_cylinder' => 'decimal:2',
        'objective_os_axis' => 'integer',
        'subjective_od_sphere' => 'decimal:2',
        'subjective_od_cylinder' => 'decimal:2',
        'subjective_od_axis' => 'integer',
        'subjective_od_add' => 'decimal:2',
        'subjective_os_sphere' => 'decimal:2',
        'subjective_os_cylinder' => 'decimal:2',
        'subjective_os_axis' => 'integer',
        'subjective_os_add' => 'decimal:2',
    ];

    public function hasStructuredObjective(): bool
    {
        return collect([
            $this->objective_od_sphere, $this->objective_od_cylinder, $this->objective_od_axis,
            $this->objective_os_sphere, $this->objective_os_cylinder, $this->objective_os_axis,
        ])->contains(fn ($value) => $value !== null && $value !== '');
    }

    public function hasStructuredSubjective(): bool
    {
        return collect([
            $this->subjective_od_sphere, $this->subjective_od_cylinder, $this->subjective_od_axis,
            $this->subjective_os_sphere, $this->subjective_os_cylinder, $this->subjective_os_axis,
        ])->contains(fn ($value) => $value !== null && $value !== '');
    }

    public static function formatPower($value): ?string
    {
        if ($value === null || $value === '') return null;

        return sprintf('%+.2f', (float) $value);
    }

    public static function formatPrescription($sphere, $cylinder = null, $axis = null): string
    {
        if ($sphere === null || $sphere === '') return '';

        $result = self::formatPower($sphere) . ' DS';
        if ($cylinder !== null && $cylinder !== '') {
            $result = self::formatPower($sphere) . ' / ' . self::formatPower($cylinder) . ' DC';
            if ($axis !== null && $axis !== '') $result .= ' × ' . str_pad((string) $axis, 3, '0', STR_PAD_LEFT) . '°';
        }

        return $result;
    }

    public function subjectiveRx(string $eye): string
    {
        $eye = strtolower($eye) === 'os' ? 'os' : 'od';
        $structured = self::formatPrescription(
            $this->{"subjective_{$eye}_sphere"},
            $this->{"subjective_{$eye}_cylinder"},
            $this->{"subjective_{$eye}_axis"}
        );

        return $structured !== '' ? $structured : (string) $this->{'refraction' . strtoupper($eye)};
    }


    public function consultation()
    {
        return $this->belongsTo('App\Models\Consultations');
    }

    public function lensOrder()
{
    // Since the foreign key is on the LensOrder table:
    return $this->hasOne(LensOrder::class, 'refraction_id');
}

    public function dispensingAuthorizedBy()
    {
        return $this->belongsTo(User::class, 'dispensing_authorized_by');
    }

    
    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }


}
