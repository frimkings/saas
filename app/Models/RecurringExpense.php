<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBusinessLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\CarbonInterface;

/** A bill that repeats (rent, salaries, utilities): it comes due on schedule and a person records or skips it. */
class RecurringExpense extends Model
{
    use SoftDeletes, BelongsToBranch, HasBusinessLine;

    public const FREQUENCIES = ['weekly' => 'Every week', 'monthly' => 'Every month', 'quarterly' => 'Every 3 months', 'yearly' => 'Every year'];

    protected $fillable = [
        'business_line', 'expense_category_id', 'description', 'payee', 'amount',
        'payment_method', 'frequency', 'next_due_date', 'is_active', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'next_due_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** The date after $date on this schedule; month ends stay month ends (Jan 31 → Feb 28). */
    public static function after(CarbonInterface $date, string $frequency): CarbonInterface
    {
        return match ($frequency) {
            'weekly' => $date->copy()->addWeek(),
            'quarterly' => $date->copy()->addMonthsNoOverflow(3),
            'yearly' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()->addMonthNoOverflow(),
        };
    }

    /** Move on to the next due date, once this one is recorded or skipped. */
    public function advance(): void
    {
        $this->update(['next_due_date' => self::after($this->next_due_date, $this->frequency)]);
    }

    public function isOverdue(): bool
    {
        return $this->next_due_date->lt(today());
    }
}
