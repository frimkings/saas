<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An extra feature clinics can add to their plan, and its monthly price. See App\Services\ClinicAddonService. */
class PlatformAddon extends Model
{
    protected $fillable = ['feature', 'monthly_price', 'currency', 'is_offered'];

    protected $casts = ['monthly_price' => 'decimal:2', 'is_offered' => 'boolean'];
}
