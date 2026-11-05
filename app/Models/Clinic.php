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
        'patient_prefix',
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

    /** Words left out when suggesting initials: "V.Y. Odei Eyecare Center" is VYO, not VYOEC. */
    private const PREFIX_SKIP_WORDS = ['the', 'and', 'of', 'eye', 'eyes', 'eyecare', 'clinic', 'clinics', 'center', 'centre',
        'optical', 'opticals', 'optometry', 'optometric', 'vision', 'care', 'hospital', 'services', 'ltd', 'limited', 'company', 'co'];

    /** 2–3 capital letters from the name, e.g. Vision Space → VS. */
    public static function suggestPatientPrefix(string $name): string
    {
        $words = collect(preg_split('/[^A-Za-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY));
        $kept = $words->reject(fn ($w) => in_array(strtolower($w), self::PREFIX_SKIP_WORDS, true))->values();
        // A name that is all common words ("Vision Eye Clinic") still gets its own initials.
        $kept = $kept->isEmpty() ? $words : $kept;
        if ($kept->count() === 1 && $words->count() > 1) {
            $kept = $words; // "Vision Space": skipping "Vision" alone would leave just "S"
        }

        $prefix = $kept->count() === 1
            ? substr($kept->first(), 0, 2)
            : $kept->take(3)->map(fn ($w) => $w[0])->implode('');

        return strlen($prefix) >= 2 ? strtoupper($prefix) : 'PX';
    }

    /** The initials patient numbers start with; suggested from the name until first saved. */
    public function patientPrefix(): string
    {
        return $this->patient_prefix ?: self::suggestPatientPrefix((string) $this->name);
    }

    /** Initials can change only until the first number in the new format is given out. */
    public function patientPrefixLocked(): bool
    {
        return \Illuminate\Support\Facades\DB::table('patient_number_counters')->where('clinic_id', $this->id)->exists();
    }
}
