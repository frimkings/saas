<?php

namespace App\Services\Messaging;

interface SmsDriver
{
    /** @return array{success: bool, message_id?: ?string, error?: string, response?: array} */
    public function send(SmsCredentials $credentials, string $to, string $message): array;

    /** @return array{success: bool, response?: array, error?: string} */
    public function balance(SmsCredentials $credentials): array;
}
