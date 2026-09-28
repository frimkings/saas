<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Selling price per pair for one stock lens range; see OpticalLensPriceList. */
class OpticalLensPrice extends Model
{
    use BelongsToClinic;

    protected $fillable = ['range_key', 'specs', 'pair_price', 'updated_by'];

    protected $casts = ['specs' => 'array', 'pair_price' => 'decimal:2'];

    public function rules(): HasMany
    {
        return $this->hasMany(OpticalLensPriceRule::class)->orderBy('id');
    }
}
