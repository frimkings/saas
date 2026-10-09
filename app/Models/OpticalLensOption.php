<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** A lens design or treatment as this clinic names it (see App\Support\Optical\LensOptions). */
class OpticalLensOption extends Model
{
    use BelongsToClinic;

    protected $fillable = ['kind', 'code', 'name', 'design', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
