<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class OpticalStockCount extends Model
{
    use BelongsToBranch;

    public const STATUSES = ['counting' => 'Counting', 'submitted' => 'Awaiting approval', 'approved' => 'Approved', 'cancelled' => 'Cancelled'];

    protected $fillable = ['count_number', 'scope', 'scope_specs', 'title', 'status', 'notes', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'ledger_position'];

    protected $casts = ['scope_specs' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];

    public function lines()
    {
        return $this->hasMany(OpticalStockCountLine::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
