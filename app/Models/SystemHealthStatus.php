<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\BelongsToOptionalBranch;

class SystemHealthStatus extends Model
{
    use BelongsToClinic, BelongsToOptionalBranch;
    protected $fillable = [
        'key',
        'value',
        'checked_at',
    ];

    protected $casts = [
        'value' => 'array',
        'checked_at' => 'datetime',
    ];

    public static function record(string $key, array $value = []): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'checked_at' => now(),
            ]
        );
    }

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }
}
