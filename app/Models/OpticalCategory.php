<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpticalCategory extends Model
{
    use BelongsToClinic, SoftDeletes;

    protected $fillable = ['code', 'name', 'default_markup', 'is_active', 'description'];

    protected $casts = ['default_markup' => 'decimal:2', 'is_active' => 'boolean'];

    public function products(): HasMany
    {
        return $this->hasMany(OpticalProduct::class);
    }

    public function legacyProducts(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function getGroupAttribute(): string
    {
        $label = strtolower($this->name.' '.$this->code);
        if (preg_match('/frame|eyewear|sunglass/', $label)) return 'frames';
        if (preg_match('/progressive|\bpal\b/', $label)) return 'progressive';
        if (str_contains($label, 'bifocal')) return 'bifocal';
        if (preg_match('/single[ -]?vision|\bsv[ -]/', $label)) return 'single_vision';
        if (preg_match('/coating|treatment|blue cut|anti.reflective/', $label)) return 'coatings';
        return 'other';
    }
}
