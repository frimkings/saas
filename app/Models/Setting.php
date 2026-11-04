<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToClinic;

class Setting extends Model
{
    use HasFactory, BelongsToClinic;

    public const DEFAULT_CLINIC_NAME    = 'My Eye Clinic';
    public const DEFAULT_CLINIC_ADDRESS = 'Your Address Here';
    public const DEFAULT_CLINIC_CONTACT = 'Your Contact Number';
    public const DEFAULT_CLINIC_EMAIL   = 'info@clinic.com';
    public const DEFAULT_VA_NOTATION    = '6m';
    public const DEFAULT_CURRENCY       = 'GH₵';

    public const CURRENCIES = [
        'GH₵' => 'Ghana Cedi (GH₵)',
        '$'   => 'US Dollar ($)',
        '€'   => 'Euro (€)',
        '£'   => 'British Pound (£)',
        '₦'   => 'Nigerian Naira (₦)',
        'KSh' => 'Kenyan Shilling (KSh)',
        'R'   => 'South African Rand (R)',
        'UGX' => 'Ugandan Shilling (UGX)',
        'TZS' => 'Tanzanian Shilling (TZS)',
        'XOF' => 'West African CFA (XOF)',
        'EGP' => 'Egyptian Pound (EGP)',
        'ETB' => 'Ethiopian Birr (ETB)',
    ];

    public function getClinicNameAttribute($value): ?string
    {
        // Registration owns the clinic identity; legacy records keep their saved name.
        return $this->clinic?->name ?? $value;
    }

    protected $table = 'settings';

    protected $fillable = [
        'clinic_name',
        'clinic_logo',
        'clinic_address',
        'clinic_contact',
        'clinic_email',
        'backup_extra_paths',
        'report_enabled',
        'report_frequency',
        'report_day',
        'report_time',
        'report_recipients',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_address',
        'smtp_from_name',
        'va_notation',
        'currency_symbol',
        'sms_api_url',
        'sms_api_key',
        'sms_sender_id',
        'sms_sender_id_requested',
        'sms_sender_id_status',
        'sms_sender_id_note',
        'sms_sender_id_requested_at',
        'clinic_link',
        'sms_enabled',
        'birthday_sms_filter',
        'birthday_sms_custom_months',
        'recall_sms_enabled',
        'recall_months',
        'spectacle_renewal_enabled',
        'spectacle_renewal_reminder_days',
        'whatsapp_enabled',
        'whatsapp_phone_number_id',
        'whatsapp_access_token',
        'whatsapp_appt_template',
        'whatsapp_appt_template_lang',
        'whatsapp_birthday_template',
        'whatsapp_recall_template',
        'whatsapp_renewal_template',
        'whatsapp_bulk_channel',
        'trial_started_at',
        'aftercare_sms_days',
        'clinical_recall_lead_days',
        'balance_reminder_first_days',
        'balance_reminder_every_days',
        'balance_reminder_max',
        'review_link',
        'visit_receipts_enabled',
        'closing_time',
        'reminder_appointment_hours',
        'reminder_due_days',
        'reminder_uncollected_days',
        'reminder_owner_uncollected_days',
        'reminder_stale_days',
        'reminder_expiry_days',
        'whatsapp_link',
        'website_link',
        'booking_link',
        'facebook_link',
        'instagram_link',
        'tiktok_link',
    ];

    protected $casts = [
        'backup_extra_paths'         => 'array',
        'visit_receipts_enabled'     => 'boolean',
        'report_enabled'             => 'boolean',
        'report_day'                 => 'integer',
        'report_recipients'          => 'array',
        'sms_enabled'                => 'boolean',
        'sms_sender_id_requested_at' => 'datetime',
        'birthday_sms_custom_months' => 'integer',
        'recall_sms_enabled'              => 'boolean',
        'recall_months'                   => 'integer',
        'spectacle_renewal_enabled'       => 'boolean',
        'spectacle_renewal_reminder_days' => 'integer',
        'whatsapp_enabled'                => 'boolean',
        'trial_started_at'                => 'date',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetCached());
        static::deleted(fn () => static::forgetCached());
    }

    public static function currency(): string
    {
        return static::getSettings()->currency_symbol ?? self::DEFAULT_CURRENCY;
    }

    public static function clearCurrencyCache(): void
    {
        static::forgetCached();
    }

    /** Drops this request's cached settings so the next getSettings() reads the database. */
    public static function forgetCached(): void
    {
        app(\App\Support\RequestMemo::class)->forget('settings:');
    }

    public static function getSettings()
    {
        $context = app(\App\Support\Tenancy\TenantContext::class);
        if (config('tenancy.enabled') && ! $context->resolved()) {
            // No clinic is signed in (guest pages, platform admin, platform-wide jobs such as
            // backups). Never borrow another clinic's row: that leaks its name, email and
            // settings to everyone. Return unsaved platform defaults instead.
            $support = PlatformSetting::support();

            return new static([
                'clinic_name' => $support['name'],
                'clinic_contact' => $support['phone'],
                'clinic_email' => $support['email'],
            ]);
        }

        // Read once per request per clinic: layouts, middleware and components each ask for it.
        // Callers get their own copy, so an edit that is never saved stays with that caller.
        $settings = app(\App\Support\RequestMemo::class)->remember('settings:'.($context->clinicId() ?? 0), function () use ($context) {
            $settings = static::first() ?? static::create([
                'clinic_name' => self::DEFAULT_CLINIC_NAME,
                'clinic_address' => self::DEFAULT_CLINIC_ADDRESS,
                'clinic_contact' => self::DEFAULT_CLINIC_CONTACT,
                'clinic_email' => self::DEFAULT_CLINIC_EMAIL,
            ]);
            // The clinic name accessor needs the clinic; reuse the one already resolved.
            if ($context->clinic() && (int) $settings->clinic_id === $context->clinicId()) {
                $settings->setRelation('clinic', $context->clinic());
            }

            return $settings;
        });

        return clone $settings;
    }

    public function needsSetup(): bool
    {
        return count($this->missingSetupFields()) > 0;
    }

    public function missingSetupFields(): array
    {
        $missing = [];

        if ($this->isDefaultPlaceholder($this->clinic_address, self::DEFAULT_CLINIC_ADDRESS)) {
            $missing[] = 'Clinic address';
        }

        if ($this->isDefaultPlaceholder($this->clinic_contact, self::DEFAULT_CLINIC_CONTACT)) {
            $missing[] = 'Contact number';
        }

        if ($this->isDefaultPlaceholder($this->clinic_email, self::DEFAULT_CLINIC_EMAIL)) {
            $missing[] = 'Email address';
        }

        return $missing;
    }

    private function isBlankOrDefault(?string $value, string $default): bool
    {
        $value = trim((string) $value);

        return $value === '' || strcasecmp($value, $default) === 0;
    }

    private function isDefaultPlaceholder(?string $value, string $default): bool
    {
        return strcasecmp(trim((string) $value), $default) === 0;
    }

    /** The logo's path on the public disk, or null when there is none or it looks unsafe. */
    public function logoPath(): ?string
    {
        if (!$this->clinic_logo) {
            return null;
        }

        // Block path traversal — no ".." segments allowed anywhere in the path
        if (str_contains($this->clinic_logo, '..')) {
            return null;
        }

        // Allow only safe characters: alphanumerics, dashes, underscores, dots, forward slashes
        if (!preg_match('/^[\w\-\.\/]+$/', $this->clinic_logo)) {
            return null;
        }

        return ltrim($this->clinic_logo, '/');
    }

    /**
     * The logo embedded in printed documents (letters, receipts, reports). Read through the
     * public disk, which is a bucket on the hosted app: the server's own folder has no logo
     * there, so documents printed without one. Read once per request.
     */
    public function logoDataUri(): ?string
    {
        $path = $this->logoPath();

        if (!$path) {
            return null;
        }

        return app(\App\Support\RequestMemo::class)->remember('logo:'.$path, function () use ($path) {
            $why = fn (string $reason) => \Illuminate\Support\Facades\Log::warning('Clinic logo left off a printed document: ' . $reason, ['clinic_id' => $this->clinic_id, 'path' => $path]);
            try {
                $disk = \Illuminate\Support\Facades\Storage::disk('public');
                if (! $disk->exists($path)) {
                    $why('the file is not in storage (upload it again on Settings)');

                    return null;
                }
                $bytes = $disk->size($path);
                if ($bytes > 10 * 1024 * 1024) {
                    $why("the file is {$bytes} bytes, over 10 MB");

                    return null;
                }

                // A small file can still be thousands of pixels wide; the PDF engine then runs out of
                // memory and the print is a blank 500. Shrink it once and keep the shrunk copy;
                // a logo that can't be shrunk is tried again in an hour, not on every print.
                $key = 'printable-logo:' . md5($path . ':' . $bytes);
                $contents = \Illuminate\Support\Facades\Cache::get($key);
                if ($contents === null) {
                    $contents = self::printableLogo($disk->get($path) ?: '', $why) ?? '';
                    $contents === ''
                        ? \Illuminate\Support\Facades\Cache::put($key, '', now()->addHour())
                        : \Illuminate\Support\Facades\Cache::forever($key, $contents);
                }
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
            if ($contents === '') {
                return null;
            }

            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'image/png';

            return 'data:' . $mimeType . ';base64,' . base64_encode($contents);
        });
    }

    /** Largest side of a logo in printed documents; they show it well under 200px. */
    public const PRINT_LOGO_MAX_PX = 600;

    /**
     * The logo as is when small (or not a bitmap, e.g. SVG), else a PNG at most PRINT_LOGO_MAX_PX a side;
     * null if it can't be made printable, with the reason passed to $why.
     */
    public static function printableLogo(string $contents, ?\Closure $why = null): ?string
    {
        $why ??= fn () => null;
        $small = strlen($contents) <= 2 * 1024 * 1024;
        if ($contents === '') {
            $why('the file is empty');

            return null;
        }
        $size = @getimagesizefromstring($contents);
        if (! $size) {
            return $small ? $contents : null;
        }
        if ($small && $size[0] <= self::PRINT_LOGO_MAX_PX && $size[1] <= self::PRINT_LOGO_MAX_PX) {
            return $contents;
        }
        // The PDF engine copies a JPEG in without opening its pixels, so a big one is still safe.
        $fallback = fn (string $reason) => $small && $size[2] === IMAGETYPE_JPEG ? $contents : $why($reason);

        // Too big to even open safely, or no GD: print without a logo rather than fail.
        if ($size[0] * $size[1] > 25_000_000) {
            return $fallback("it is {$size[0]}x{$size[1]} pixels, too large to shrink");
        }
        if (! function_exists('imagecreatefromstring')) {
            return $fallback('the server has no GD image library to shrink it');
        }

        // Opening it takes about 5 bytes a pixel; this runs once per logo (the result is cached).
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && ini_parse_quantity($limit) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
        $image = @imagecreatefromstring($contents);
        if (! $image) {
            @ini_set('memory_limit', $limit);

            return $fallback('GD could not open it (' . image_type_to_mime_type($size[2]) . ", {$size[0]}x{$size[1]})");
        }
        $scale = self::PRINT_LOGO_MAX_PX / max($size[0], $size[1]);
        $small = imagecreatetruecolor(max(1, (int) round($size[0] * $scale)), max(1, (int) round($size[1] * $scale)));
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 255, 255, 255, 127));
        imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $size[0], $size[1]);
        imagedestroy($image);

        ob_start();
        imagepng($small, null, 9);
        imagedestroy($small);
        @ini_set('memory_limit', $limit);

        return ob_get_clean() ?: null;
    }
}
