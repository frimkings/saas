<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Consultations;
use Illuminate\Database\Eloquent\Casts\Attribute;
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'staff_id',
        'gender',
        'date_of_birth',
        'department',
        'hire_date',
        'avatar',
        'preferred_workspace',
    ];
    // is_active, last_password_changed_at: set via direct assignment only, not mass-assignable

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
   

    protected $casts = [
        'email_verified_at'          => 'datetime',
        'last_password_changed_at'   => 'datetime',
        'date_of_birth'              => 'date',
        'hire_date'                  => 'date',
        'is_active'                  => 'boolean',
        'is_platform_admin'          => 'boolean',
        'must_change_password'       => 'boolean',
    ];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? asset('storage/' . $this->avatar) : null;
    }

    public function getServiceLengthAttribute(): string
    {
        if (!$this->hire_date) return '—';
        return $this->hire_date->diffForHumans(now(), true);
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth ? $this->date_of_birth->age : null;
    }


    public function getRoleNameAttribute(): string
    {
        return $this->getRoleNames()->first() ?? 'Unknown';
    }

    public function consultation()
    {
        // Assumes 'user_id' is the foreign key on the consultations table
        return $this->hasOne('App\Models\Consultations');
    }


    function refraction ()  {
        return $this->hasOne('App\Models\Refractions');
        
    }

    public function loginLogs() {
    return $this->hasMany(LoginLog::class);
}

public function latestLogin() {
    return $this->hasOne(LoginLog::class)->latestOfMany('login_at');
}

public function clinics()
{
    return $this->belongsToMany(Clinic::class)
        ->withPivot(['status', 'is_default', 'staff_identifier', 'clinic_role', 'invited_by', 'joined_at', 'left_at'])
        ->withTimestamps();
}

public function branches()
{
    return $this->belongsToMany(Branch::class)
        ->withPivot(['status', 'is_default', 'joined_at', 'left_at'])
        ->withTimestamps();
}

/**
 * Staff of the clinic being worked in. Users are shared across clinics (clinic_user), so any
 * user list shown to a clinic must go through this; otherwise it shows other clinics' staff.
 */
public function scopeInCurrentClinic($query, array $statuses = ['active', 'inactive'])
{
    $clinicId = app(\App\Support\Tenancy\TenantContext::class)->clinicId();
    if ($clinicId === null) {
        return config('tenancy.enabled') ? $query->whereRaw('1 = 0') : $query;
    }

    return $query->whereHas('clinics', fn ($clinics) => $clinics->whereKey($clinicId)->whereIn('clinic_user.status', $statuses));
}

}
