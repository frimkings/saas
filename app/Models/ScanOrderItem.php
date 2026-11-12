<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** One scan on an order, with the eye(s) and the price it was charged at. */
class ScanOrderItem extends Model
{
    use BelongsToClinic;

    public const EYES = ['OU' => 'Both eyes', 'OD' => 'Right eye (OD)', 'OS' => 'Left eye (OS)'];

    protected $fillable = ['scan_order_id', 'diagnostic_scan_id', 'scan_name', 'eye', 'quantity', 'unit_price', 'line_total', 'sale_item_id'];

    protected $casts = ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'quantity' => 'integer'];

    public function order()
    {
        return $this->belongsTo(ScanOrder::class, 'scan_order_id');
    }

    public function scan()
    {
        return $this->belongsTo(DiagnosticScan::class, 'diagnostic_scan_id');
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    /** "OCT Macula (both eyes)". */
    public function label(): string
    {
        return $this->scan_name.($this->eye ? ' ('.strtolower(self::EYES[$this->eye] ?? $this->eye).')' : '');
    }
}
