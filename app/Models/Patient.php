<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use App\Models\Concerns\BelongsToClinic;
use App\Support\Tenancy\TenantContext;

class Patient extends Model
{
    use HasFactory, SoftDeletes, BelongsToClinic;

    protected $fillable = [
        'uuid',
        'home_branch_id',
        'user_id',
        'pxnumber',
        'name',
        'contact',
        'gender',
        'dob',
        'address',
        'occupation',
        'email',
        'civil_status',
        'recall_sms_sent_at',
        'next_exam_due_on',
        'clinical_recall_sent_for',
        'sms_opt_out',
        'whatsapp_opt_out',
        'marketing_opt_out',
        'insurer_id',
        'insurance_member_id',
        'insurance_member_name',
        'insurance_policy_number',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();

            $branchId = app(TenantContext::class)->branchId();
            if ($branchId === null && ! config('tenancy.enabled')) {
                $branchId = Branch::query()
                    ->where('clinic_id', $model->clinic_id)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->value('id');
            }
            $model->home_branch_id = $branchId;
        });
    }

    /**
     * Registers the patient with the clinic's next number: initials, year and month, then that
     * month's count, e.g. VS-2611-042 (3 digits, 4 past 999). The month's counter row is locked
     * until the patient is saved, so numbers are never shared and never skipped. Earlier
     * patients keep the numbers they had (PX-..., or one brought from other software).
     */
    public static function createWithGeneratedPxNumber(array $attributes): self
    {
        unset($attributes['pxnumber']);

        $context = app(TenantContext::class);
        $clinic = $context->clinic() ?? (isset($attributes['clinic_id']) ? Clinic::find($attributes['clinic_id']) : null);
        $period = now()->format('ym');

        return \Illuminate\Support\Facades\DB::transaction(function () use ($attributes, $clinic, $period) {
            $counters = \Illuminate\Support\Facades\DB::table('patient_number_counters');
            $key = ['clinic_id' => $clinic?->id ?? 0, 'period' => $period];
            $counters->insertOrIgnore($key + ['last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $number = (int) (clone $counters)->where($key)->lockForUpdate()->value('last_number');

            $prefix = $clinic?->patientPrefix() ?? 'PX';
            // Keep the initials the first number used, even if the clinic is renamed later.
            if ($clinic && ! $clinic->patient_prefix) {
                $clinic->forceFill(['patient_prefix' => $prefix])->saveQuietly();
            }

            // Skip any number already held, e.g. one brought in from other software.
            do {
                $number++;
                $pxnumber = $prefix . '-' . $period . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
                $taken = static::withoutGlobalScopes()->withTrashed()->where('pxnumber', $pxnumber)
                    ->where('clinic_id', $clinic?->id ?? $attributes['clinic_id'] ?? null)->exists();
            } while ($taken);

            (clone $counters)->where($key)->update(['last_number' => $number, 'updated_at' => now()]);

            return static::create($attributes + ['pxnumber' => $pxnumber]);
        });
    }

    protected $casts = [
        'recall_sms_sent_at' => 'datetime',
        'next_exam_due_on' => 'date',
        'clinical_recall_sent_for' => 'date',
        'sms_opt_out'        => 'boolean',
        'whatsapp_opt_out'   => 'boolean',
        'marketing_opt_out'  => 'boolean',
    ];

    /**
     * Name, phone or patient number contains $term (the quick patient pickers). On MySQL the
     * search reads patients_quick_search_index, which holds all three columns, so patients
     * that don't match are skipped in the index instead of being read row by row.
     */
    public function scopeQuickSearch($query, ?string $term)
    {
        $like = '%'.$term.'%';
        $query->where(fn ($q) => $q->where('name', 'like', $like)
            ->orWhere('contact', 'like', $like)
            ->orWhere('pxnumber', 'like', $like));

        if ($term !== null && $term !== '' && in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->forceIndex('patients_quick_search_index');
        }

        return $query;
    }

    // Helpful helper for the Blade file
    public function getAgeAttribute()
    {
        return \Illuminate\Support\Carbon::parse($this->dob)->age;
    }

    protected $hidden = [
        'remember_token',
        'created_at',
        'updated_at',
        'user_id',
        // 'pxnumber',
        // 'id'

    ];




public function insurer()
{
    return $this->belongsTo(Insurer::class);
}

public function homeBranch()
{
    return $this->belongsTo(Branch::class, 'home_branch_id');
}

public function latestInsuranceClaim()
{
    return $this->hasOne(InsuranceClaim::class)->latestOfMany();
}

public function clearances()
{
    return $this->hasMany(CashierPatientClearance::class);
}

public function consultations()
{

   return $this->hasMany('App\Models\Consultations');

}

public function appointments()
{
    return $this->hasMany(Appointments::class);
}

public function documents()
{
    return $this->hasMany(PatientDocument::class);
}

public function auditTrails()
{
    return $this->hasMany(AuditTrail::class);
}

/** Payments recorded in the clinic's previous software, for reference only (never sales). */
public function legacyPayments()
{
    return $this->hasMany(PatientLegacyPayment::class);
}

}
