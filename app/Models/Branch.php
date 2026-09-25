<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'clinic_id',
        'code',
        'name',
        'address',
        'contact',
        'email',
        'timezone',
        'receipt_prefix',
        'public_booking_key',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            $branch->uuid ??= (string) Str::uuid();
        });
    }

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['status', 'is_default', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    public function patients()
    {
        return $this->hasMany(Patient::class, 'home_branch_id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointments::class);
    }

    public function consultations()
    {
        return $this->hasMany(Consultations::class);
    }

    public function clearances()
    {
        return $this->hasMany(CashierPatientClearance::class);
    }

    public function lensOrders()
    {
        return $this->hasMany(LensOrder::class);
    }
}
