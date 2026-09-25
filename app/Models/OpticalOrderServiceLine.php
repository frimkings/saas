<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalOrderServiceLine extends Model
{
    use BelongsToBranch;

    protected $table = 'optical_order_services';

    protected $fillable = ['lens_order_id', 'optical_service_id', 'service_code', 'description', 'quantity', 'unit_price', 'line_total', 'requires_rx', 'requires_frame'];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'requires_rx' => 'boolean',
        'requires_frame' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(LensOrder::class, 'lens_order_id');
    }

    public function service()
    {
        return $this->belongsTo(OpticalService::class, 'optical_service_id');
    }
}
