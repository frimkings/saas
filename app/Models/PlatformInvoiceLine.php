<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One charge on a platform invoice: the plan, extra branches, or an add-on. */
class PlatformInvoiceLine extends Model
{
    protected $fillable = ['platform_invoice_id', 'description', 'amount', 'clinic_addon_id', 'sort'];

    protected $casts = ['amount' => 'decimal:2'];
}
