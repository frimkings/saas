<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Models\Concerns\BelongsToBranch;

class CashierPatientClearance extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch;
    protected $fillable = [
        'uuid',
        'user_id',
        'patient_id',
        'service_id',
        'payment_status',
        'doctor_status',
        'clearance_date',
        'sale_id',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }




    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function service()
    {
        return $this->belongsTo(\App\Models\Product::class, 'service_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function consultation()
    {
        return $this->hasOne(\App\Models\Consultations::class, 'clearance_id');
    }

    public function revokeLog()
    {
        return $this->hasOne(ClearanceRevokeLog::class, 'clearance_id')->latest();
    }

    public function pendingRevokeLog()
    {
        return $this->hasOne(ClearanceRevokeLog::class, 'clearance_id')
            ->where('status', ClearanceRevokeLog::STATUS_PENDING);
    }

    public function sale()
    {
        return $this->belongsTo(\App\Models\Sales::class, 'sale_id');
    }
}
