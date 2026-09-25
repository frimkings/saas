<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Models\Concerns\BelongsToClinic;
use App\Support\Tenancy\TenantCache;

class LensOption extends Model
{
    use BelongsToClinic;

    public const FAMILIES = ['Single Vision', 'Bifocal', 'Progressive'];

    protected $fillable = ['family', 'display_name'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(TenantCache::key('patient-records:lens-options:v2')));
        static::deleted(fn () => Cache::forget(TenantCache::key('patient-records:lens-options:v2')));
    }
}
