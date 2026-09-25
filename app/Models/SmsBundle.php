<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide catalogue of prepaid SMS credit bundles clinics can buy. */
class SmsBundle extends Model
{
    protected $fillable = ['name', 'credits', 'price', 'currency', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'currency' => 'GHS', 'sort_order' => 0];

    protected $casts = ['credits' => 'integer', 'price' => 'decimal:2', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('credits');
    }
}
