<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBusinessLine;

class IncomeStatementEntry extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch, HasBusinessLine;

    public const OPERATING_EXPENSE = 'operating_expense';
    public const NON_OPERATING_EXPENSE = 'non_operating_expense';
    public const TAX = 'tax';

    protected $fillable = [
        'section',
        'name',
        'amount',
        'percentage',
        'entry_date',
        'notes',
        'is_active',
        'created_by',
        'deleted_by',
        'business_line',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'entry_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public static function sections()
    {
        return [
            self::OPERATING_EXPENSE => 'Operating Expense',
            self::NON_OPERATING_EXPENSE => 'Non-operating Expense',
            self::TAX => 'Tax Percentage',
        ];
    }
}
