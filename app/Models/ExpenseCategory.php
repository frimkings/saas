<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;

class ExpenseCategory extends Model
{
    use HasFactory, BelongsToClinic;

    public const OPERATING     = 'operating_expense';
    public const NON_OPERATING = 'non_operating_expense';

    protected $fillable = ['name', 'section', 'color', 'description', 'is_active'];

    protected $attributes = ['is_active' => true];

    public static function sectionLabels(): array
    {
        return [
            self::OPERATING     => 'Operating Expense',
            self::NON_OPERATING => 'Non-operating Expense',
        ];
    }

    protected $casts = ['is_active' => 'boolean'];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
