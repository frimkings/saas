<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

class OpticalSetting extends Model
{
    use BelongsToClinic;

    protected $fillable = ['min_deposit_percentage', 'warranty_months', 'optical_disclaimer', 'ready_sms_auto', 'collection_reminder_schedule', 'stuck_job_days', 'quote_validity_days', 'pos_max_discount_percent'];

    protected $casts = ['ready_sms_auto' => 'boolean', 'collection_reminder_schedule' => 'array'];

    /** Days after "ready" on which pickup reminders go out, ascending. Empty = off. */
    public function reminderSchedule(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) $this->collection_reminder_schedule), fn ($day) => $day > 0)));
        sort($days);
        return array_slice($days, 0, 5);
    }

    /** Clinic default when optical settings have never been saved. */
    public const DEFAULT_REMINDER_SCHEDULE = [7, 14, 21];

    /** Days after which an open job with no status change counts as stuck. */
    public static function stuckJobDays(): int
    {
        return max(1, (int) (static::first()?->stuck_job_days ?? 7));
    }

    /** Days a quotation's prices hold. */
    public static function quoteValidityDays(): int
    {
        return max(1, (int) (static::first()?->quote_validity_days ?? 30));
    }

    /** Largest POS discount, as a percent of the sale, staff may give without a manager. */
    public static function posMaxDiscountPercent(): int
    {
        return min(100, max(0, (int) (static::first()?->pos_max_discount_percent ?? 10)));
    }

    public static function currentReminderSchedule(): array
    {
        $settings = static::first();
        return $settings ? $settings->reminderSchedule() : self::DEFAULT_REMINDER_SCHEDULE;
    }
}
