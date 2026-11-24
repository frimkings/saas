<?php

namespace App\Enums;

/** When to take a drug taken by mouth, with respect to meals (24 Nov 2026). */
enum MealTiming: string
{
    case BEFORE_MEALS = 'before_meals';
    case AFTER_MEALS = 'after_meals';
    case WITH_FOOD = 'with_food';
    case EMPTY_STOMACH = 'empty_stomach';
    case AT_BEDTIME = 'at_bedtime';

    public function label(): string
    {
        return match ($this) {
            self::BEFORE_MEALS => 'Before meals',
            self::AFTER_MEALS => 'After meals',
            self::WITH_FOOD => 'With food',
            self::EMPTY_STOMACH => 'On an empty stomach',
            self::AT_BEDTIME => 'At bedtime',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
