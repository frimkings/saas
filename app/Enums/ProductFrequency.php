<?php

namespace App\Enums;

enum ProductFrequency: string 
{

    case ONCE_DAILY = 'Once Daily';
    case TWICE_DAILY = 'Twice Daily';
    case THREE_TIMES_DAILY = 'Three Times Daily';
    case FOUR_TIMES_DAILY = 'Four Times Daily';
    case EVERY_4_HOURS = 'Every 4 Hours';
    case EVERY_6_HOURS = 'Every 6 Hours';
    case EVERY_8_HOURS = 'Every 8 Hours';
    case EVERY_12_HOURS = 'Every 12 Hours';
    case AS_NEEDED = 'As Needed (PRN)';
    case BEFORE_MEALS = 'Before Meals';
    case AFTER_MEALS = 'After Meals';
    case AT_BEDTIME = 'At Bedtime';
    case MORNING_ONLY = 'Morning Only';
    case EVENING_ONLY = 'Evening Only';
    case WEEKLY = 'Weekly';
    case EVERY_OTHER_DAY = 'Every Other Day';
    case CONTINUOUS = 'Continuous Use';
    case SINGLE_DOSE = 'Single Dose';

    /**
     * Get all frequency options for dropdown
     */
    public static function options(): array
    {
        return [
            self::ONCE_DAILY->value => self::ONCE_DAILY->value,
            self::TWICE_DAILY->value => self::TWICE_DAILY->value,
            self::THREE_TIMES_DAILY->value => self::THREE_TIMES_DAILY->value,
            self::FOUR_TIMES_DAILY->value => self::FOUR_TIMES_DAILY->value,
            self::EVERY_4_HOURS->value => self::EVERY_4_HOURS->value,
            self::EVERY_6_HOURS->value => self::EVERY_6_HOURS->value,
            self::EVERY_8_HOURS->value => self::EVERY_8_HOURS->value,
            self::EVERY_12_HOURS->value => self::EVERY_12_HOURS->value,
            self::AS_NEEDED->value => self::AS_NEEDED->value,
            self::BEFORE_MEALS->value => self::BEFORE_MEALS->value,
            self::AFTER_MEALS->value => self::AFTER_MEALS->value,
            self::AT_BEDTIME->value => self::AT_BEDTIME->value,
            self::MORNING_ONLY->value => self::MORNING_ONLY->value,
            self::EVENING_ONLY->value => self::EVENING_ONLY->value,
            self::WEEKLY->value => self::WEEKLY->value,
            self::EVERY_OTHER_DAY->value => self::EVERY_OTHER_DAY->value,
            self::CONTINUOUS->value => self::CONTINUOUS->value,
            self::SINGLE_DOSE->value => self::SINGLE_DOSE->value,
        ];
    }

    /**
     * Get common frequencies (for quick selection)
     */
    public static function common(): array
    {
        return [
            self::ONCE_DAILY,
            self::TWICE_DAILY,
            self::THREE_TIMES_DAILY,
            self::AS_NEEDED,
            self::AT_BEDTIME,
        ];
    }

    /**
     * Get abbreviation for frequency
     */
    public function abbreviation(): string
    {
        return match($this) {
            self::ONCE_DAILY => 'OD',
            self::TWICE_DAILY => 'BD',
            self::THREE_TIMES_DAILY => 'TDS',
            self::FOUR_TIMES_DAILY => 'QDS',
            self::EVERY_4_HOURS => 'Q4H',
            self::EVERY_6_HOURS => 'Q6H',
            self::EVERY_8_HOURS => 'Q8H',
            self::EVERY_12_HOURS => 'Q12H',
            self::AS_NEEDED => 'PRN',
            self::BEFORE_MEALS => 'AC',
            self::AFTER_MEALS => 'PC',
            self::AT_BEDTIME => 'HS',
            self::MORNING_ONLY => 'QAM',
            self::EVENING_ONLY => 'QPM',
            self::WEEKLY => 'Weekly',
            self::EVERY_OTHER_DAY => 'QOD',
            self::CONTINUOUS => 'Cont.',
            self::SINGLE_DOSE => 'Stat',
        };
    }

    /**
     * Get color badge class for frequency
     */
    public function badgeClass(): string
    {
        return match($this) {
            self::ONCE_DAILY => 'bg-info',
            self::TWICE_DAILY => 'bg-primary',
            self::THREE_TIMES_DAILY, self::FOUR_TIMES_DAILY => 'bg-warning',
            self::AS_NEEDED => 'bg-secondary',
            self::AT_BEDTIME, self::MORNING_ONLY, self::EVENING_ONLY => 'bg-success',
            default => 'bg-dark',
        };
    }
}