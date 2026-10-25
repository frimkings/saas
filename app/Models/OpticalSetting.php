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

    /** "3, 10, 30" → [3, 10, 30]: up to 5 different whole days from 1 to 365. Blank = off. */
    public static function parseSchedule(string $input, string $field = 'reminder_schedule'): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $input) ?: []), fn ($part) => $part !== ''));
        $fail = fn (string $message) => throw \Illuminate\Validation\ValidationException::withMessages([$field => $message]);
        if (count($parts) > 5) $fail('Enter at most 5 reminder days.');
        $days = [];
        foreach ($parts as $part) {
            if (! ctype_digit($part) || (int) $part < 1 || (int) $part > 365) $fail('Reminder days must be whole numbers from 1 to 365, e.g. 3, 10, 30.');
            $days[] = (int) $part;
        }
        if (count(array_unique($days)) !== count($days)) $fail('Each reminder day can only be listed once.');
        sort($days);
        return $days;
    }

    public static function currentReminderSchedule(): array
    {
        $settings = static::first();
        return $settings ? $settings->reminderSchedule() : self::DEFAULT_REMINDER_SCHEDULE;
    }
}
