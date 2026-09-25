<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Models\Concerns\BelongsToBranch;

class OnlineBooking extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'uuid',
        'name',
        'phone',
        'email',
        'service',
        'preferred_date',
        'notes',
        'status',
        'converted_patient_id',
        'converted_appointment_id',
    ];

    protected $casts = [
        'preferred_date' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function convertedPatient()
    {
        return $this->belongsTo(Patient::class, 'converted_patient_id');
    }

    public function convertedAppointment()
    {
        return $this->belongsTo(Appointments::class, 'converted_appointment_id');
    }
}
