<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use App\Support\OpticalServices;
use Illuminate\Database\Eloquent\Model;

class OpticalService extends Model
{
    use BelongsToClinic;

    protected $fillable = ['code', 'name', 'price', 'requires_rx', 'requires_frame', 'is_active'];

    protected $casts = [
        'price' => 'decimal:2',
        'requires_rx' => 'boolean',
        'requires_frame' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function ensureTemplates(): void
    {
        foreach (OpticalServices::TYPES as $code => $template) {
            static::firstOrCreate(['clinic_id' => static::clinicIdForWrite(), 'code' => $code], [
                'name' => $template['name'], 'price' => 0,
                'requires_rx' => $template['requires_rx'],
                'requires_frame' => $template['requires_frame'],
                'is_active' => false,
            ]);
        }
    }
}
