<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalLensImport extends Model
{
    use BelongsToBranch;

    protected $guarded = ['id', 'clinic_id', 'branch_id'];
    protected $casts = ['specifications' => 'array'];

    public function movements() { return $this->hasMany(OpticalProductStockMovement::class); }
    public function user() { return $this->belongsTo(User::class); }
}
