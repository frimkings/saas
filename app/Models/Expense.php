<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBusinessLine;
use App\Support\BusinessLine;

class Expense extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch, HasBusinessLine;

    protected $fillable = [
        'expense_category_id',
        'expense_date',
        'description',
        'amount',
        'reference',
        'notes',
        'receipt_path',
        'recorded_by',
        'business_line',
    ];

    // Clinic and optical expenses are kept apart; every screen reads one line only.
    public const CLINIC = BusinessLine::CLINIC;
    public const OPTICAL = BusinessLine::OPTICAL;

    protected $casts = [
        'expense_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    public function getReceiptUrlAttribute(): ?string
    {
        return $this->receipt_path
            ? route($this->business_line === self::OPTICAL ? 'optical.expenses.receipt' : 'admin.expenses.receipt', ['expense' => $this->id])
            : null;
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
