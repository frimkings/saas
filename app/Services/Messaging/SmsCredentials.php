<?php

namespace App\Services\Messaging;

final class SmsCredentials
{
    public function __construct(
        public readonly string $url,
        public readonly string $key,
        public readonly ?string $sender,
        public readonly bool $platformManaged,
    ) {
    }
}
