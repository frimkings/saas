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

    public static function createWithGeneratedPxNumber(array $attributes): self
    {
        unset($attributes['pxnumber']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $attributes['pxnumber'] = 'PX-' . strtoupper(bin2hex(random_bytes(5))) . '-' . now()->format('y');

            try {
                return static::create($attributes);
            } catch (QueryException $exception) {
                $isPxNumberCollision = in_array((int) ($exception->errorInfo[1] ?? 0), [19, 1062], true)
                    && str_contains(strtolower($exception->getMessage()), 'pxnumber');

                if (! $isPxNumberCollision) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to allocate a unique patient number. Please try again.');
    }

    protected $casts = [
        'recall_sms_sent_at' => 'datetime',
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

}
