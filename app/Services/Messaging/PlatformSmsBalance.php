<?php

namespace App\Services\Messaging;

use App\Models\User;
use Illuminate\Support\Facades\{Cache, Log, Mail};

/**
 * Watches the platform's EazismsPro balance. Every hosted clinic draws on this one account,
 * so it must always cover the credits clinics have already paid for.
 */
class PlatformSmsBalance
{
    public const CACHE_KEY = 'platform.eazisms_balance';

    /** @return array{balance: ?int, outstanding: int, threshold: int, low: bool, checked_at: string, error: ?string} */
    public function check(): array
    {
        try {
            $result = app(SmsDriver::class)->balance(app(SmsCredentialResolver::class)->platform());
        } catch (SmsNotConfiguredException $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        $balance     = $result['success'] && is_numeric($result['response']['balance'] ?? null) ? (int) $result['response']['balance'] : null;
        $outstanding = app(SmsCreditService::class)->outstandingCredits();
        $threshold   = max((int) config('services.eazisms.low_balance', 1000), $outstanding);

        $status = [
            'balance'     => $balance,
            'outstanding' => $outstanding,
            'threshold'   => $threshold,
            'low'         => $balance !== null && $balance < $threshold,
            'checked_at'  => now()->toIso8601String(),
            'error'       => $result['success'] ? null : ($result['error'] ?? 'Unknown gateway error.'),
        ];

        Cache::forever(self::CACHE_KEY, $status);

        if ($status['low'] || $status['error']) {
            $this->alert($status);
        }

        return $status;
    }

    public function last(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /** Email platform admins at most once a day. */
    private function alert(array $status): void
    {
        if (!Cache::add('platform.eazisms_balance_alert:' . now()->toDateString(), true, now()->endOfDay())) {
            return;
        }

        $body = $status['error']
            ? "The platform SMS balance could not be checked: {$status['error']}"
            : "The platform EazismsPro balance is {$status['balance']} credits. Clinics hold {$status['outstanding']} prepaid credits, "
              . "and the alert level is {$status['threshold']}. Top up the EazismsPro account before SMS starts failing for every hosted clinic.";

        Log::warning('Platform SMS balance alert', $status);

        foreach (User::where('is_platform_admin', true)->whereNotNull('email')->pluck('email') as $email) {
            try {
                Mail::raw($body, fn ($message) => $message->to($email)->subject('Platform SMS balance is low'));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
