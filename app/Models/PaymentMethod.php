<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** One of a clinic's payment methods for its clinical or optical tills. See App\Support\PaymentMethods. */
class PaymentMethod extends Model
{
    use BelongsToClinic;

    protected $fillable = ['business_line', 'key', 'label', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];
}
