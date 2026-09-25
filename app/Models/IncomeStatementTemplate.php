<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;
use App\Models\Concerns\HasBusinessLine;

class IncomeStatementTemplate extends Model
{
    use HasFactory, BelongsToClinic, HasBusinessLine;

    protected $fillable = [
        'section',
        'name',
        'amount',
        'percentage',
        'notes',
        'is_active',
        'created_by',
        'business_line',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
