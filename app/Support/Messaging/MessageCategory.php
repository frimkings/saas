<?php

namespace App\Support\Messaging;

class MessageCategory
{
    public const TRANSACTIONAL = 'transactional';
    public const MARKETING = 'marketing';

    /** Template keys patients receive only while they accept marketing messages. */
    private const MARKETING_KEYS = ['birthday', 'birthday_wish', 'recall', 'patient_recall', 'spectacle_renewal', 'custom_broadcast', 'campaign'];

    public static function for(?string $templateKey): string
    {
        if ($templateKey === null) {
            return self::TRANSACTIONAL;
        }

        foreach (self::MARKETING_KEYS as $key) {
            if ($templateKey === $key || str_starts_with($templateKey, $key . '_')) {
                return self::MARKETING;
            }
        }

        return self::TRANSACTIONAL;
    }
}
