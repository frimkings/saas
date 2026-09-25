<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;

class Appointments extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'user_id',
        'title',
        'recall_category',
        'scheduled_at',
        'duration_minutes',
        'notes',
        'reminder_channel',
        'reminder_status',
        'reminder_sent_at',
        'missed_at',
        'arrived_at',
        'doctor_started_at',
        'completed_at',
        'status',
    ];

    // Ensure scheduled_at is treated as a Carbon instance
    protected $casts = [
        'scheduled_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'missed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'doctor_started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
