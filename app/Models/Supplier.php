<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;

class Supplier extends Model
{
    use HasFactory, BelongsToClinic;

    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'lead_time_days',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'lead_time_days'  => 'integer',
    ];
}
