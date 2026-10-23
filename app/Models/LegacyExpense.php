<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/**
 * An expense recorded in the clinic's previous software, kept as history.
 * Not an Expense: it never appears in the Expense Tracker, the cash book, the dashboard or finance reports.
 */
class LegacyExpense extends Model
{
    use BelongsToClinic;

    protected $guarded = [];

    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:2'];

    public function sourceLabel(): string
    {
        return ExternalImportBatch::SOURCES[$this->source] ?? ucfirst($this->source);
    }
}
