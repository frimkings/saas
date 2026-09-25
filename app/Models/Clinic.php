<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Clinic extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = ['deployment_mode' => 'hosted'];

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'status',
        'deployment_mode',
        'default_timezone',
        'default_currency',
        'domain', 'billing_email', 'billing_phone', 'billing_legal_name', 'billing_tax_id', 'billing_address', 'storage_limit_mb',
    ];

    protected static function booted(): void
    {
        static::creating(function (Clinic $clinic): void {
            $clinic->uuid ??= (string) Str::uuid();
        });
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(ClinicSubscription::class);
    }

    public function invoices() { return $this->hasMany(PlatformInvoice::class); }
    public function smsWallet() { return $this->hasOne(SmsWallet::class); }

    public function currentSubscription()
    {
        return $this->hasOne(ClinicSubscription::class)->latestOfMany();
    }

    public function defaultBranch()
    {
        return $this->hasOne(Branch::class)->where('is_default', true);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['status', 'is_default', 'staff_identifier', 'clinic_role', 'invited_by', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    public function diagnoses()
    {
        return $this->hasMany(Diagnosis::class);
    }

    public function drugs()
    {
        return $this->hasMany(Drugs::class);
    }

    public function lensOptions()
    {
        return $this->hasMany(LensOption::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function smsTemplates()
    {
        return $this->hasMany(SmsTemplate::class);
    }

    public function referralSnippets()
    {
        return $this->hasMany(ReferralSnippet::class);
    }

    public function insurers()
    {
        return $this->hasMany(Insurer::class);
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }

    public function patients()
    {
        return $this->hasMany(Patient::class);
    }
}
