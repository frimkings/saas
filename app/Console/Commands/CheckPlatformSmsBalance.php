<?php

namespace App\Console\Commands;

use App\Services\Messaging\PlatformSmsBalance;
use Illuminate\Console\Command;

class CheckPlatformSmsBalance extends Command
{
    protected $signature = 'sms:check-platform-balance';
    protected $description = 'Check the platform EazismsPro balance against the credits clinics have prepaid, and alert platform admins when it runs low.';

    public function handle(PlatformSmsBalance $balance): int
    {
        if (blank(config('services.eazisms.key'))) {
            $this->info('Platform SMS gateway is not configured; nothing to check.');
            return self::SUCCESS;
        }

        $status = $balance->check();

        if ($status['error']) {
            $this->error("Balance check failed: {$status['error']}");
            return self::FAILURE;
        }

        $this->line("Provider balance: {$status['balance']} · clinic prepaid credits: {$status['outstanding']} · alert level: {$status['threshold']}");
        $status['low'] ? $this->warn('LOW — platform admins have been alerted.') : $this->info('OK');

        return self::SUCCESS;
    }
}
