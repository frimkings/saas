<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The clinic-named optical categories used before products took one of the three fixed
 * categories (OpticalProduct::TYPES). Kept for the old orders and clinic products that
 * still name one; nothing new is filed under them.
 */
class OpticalCategory extends Model
{
    use BelongsToClinic, SoftDeletes;

    protected $fillable = ['code', 'name'];
}
