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

    /**
     * The refraction's latest spectacle order. Since 20 Nov 2026 a cancelled order stays as history and
     * the patient can be ordered again, so there may be older (cancelled) ones.
     */
    public function lensOrder()
    {
        return $this->hasOne(LensOrder::class, 'refraction_id')->latestOfMany();
    }

    /** The Optical Suite's copy of this prescription (a clinic with both modules orders from it there). */
    public function opticalPrescription()
    {
        return $this->hasOne(OpticalPrescription::class, 'refraction_id');
    }

    /**
     * Marked for glasses, with no order that is still going: none at the front desk (or the last one was
     * cancelled), and none in the Optical Suite from the same prescription (a quotation there is not an order).
     */
    public function scopeAwaitingSpectacleOrder($query)
    {
        return $query->where('dispensing_required', true)
            ->whereDoesntHave('lensOrder', fn ($order) => $order->where('status', '!=', 'Cancelled'))
            ->whereDoesntHave('opticalPrescription.orders', fn ($order) => $order->whereNotIn('status', ['Cancelled', 'Quotation']));
    }

    /** An order that is still going exists for this refraction, at the front desk or in the Optical Suite. */
    public function hasActiveLensOrder(): bool
    {
        return (bool) $this->activeLensOrder();
    }

    /** The order still going for this refraction, wherever it was made (front desk first), or null. */
    public function activeLensOrder(): ?LensOrder
    {
        return self::activeFrontDeskOrder($this->id) ?? $this->activeOpticalSuiteOrder();
    }

    /** A front-desk (Spectacles page) order still going for this refraction, deleted ones included. */
    public static function activeFrontDeskOrder(int $refractionId): ?LensOrder
    {
        return LensOrder::withTrashed()->where('refraction_id', $refractionId)->where('status', '!=', 'Cancelled')->latest('id')->first();
    }

    /** An Optical Suite order (not a quotation) made from this prescription, still going. */
    public function activeOpticalSuiteOrder(): ?LensOrder
    {
        $prescriptionId = OpticalPrescription::where('refraction_id', $this->id)->value('id');
        if (! $prescriptionId) return null;

        return LensOrder::where('optical_prescription_id', $prescriptionId)->whereNotIn('status', ['Cancelled', 'Quotation'])->latest('id')->first();
    }

    /** "already has an order" or "ordered in the Optical Suite (ORD-…)", for messages. */
    public function activeOrderLabel(): ?string
    {
        if (self::activeFrontDeskOrder($this->id)) return 'already has an order';
        $suite = $this->activeOpticalSuiteOrder();

        return $suite ? "ordered in the Optical Suite ({$suite->order_id})" : null;
    }

    public function dispensingAuthorizedBy()
    {
        return $this->belongsTo(User::class, 'dispensing_authorized_by');
    }

    /** Changes made after the author's first 24 hours, or by someone else, newest first. */
    public function corrections()
    {
        return $this->hasMany(RefractionCorrection::class, 'refraction_id')->latest('id');
    }

    /** The author may change it freely for this long; after that, and for anyone else, a change is a correction. */
    public const FREE_EDIT_HOURS = 24;

    public function editableFreelyBy(?int $userId): bool
    {
        return $userId && (int) $this->user_id === $userId
            && $this->created_at && $this->created_at->gt(now()->subHours(self::FREE_EDIT_HOURS));
    }

    
    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }


}
