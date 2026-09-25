<?php

namespace App\Support\Messaging;

/**
 * Click-to-chat link (wa.me) that opens WhatsApp on the staff member's device with the
 * message pre-typed. Free and needs no Meta setup, but a person must press Send.
 */
class WhatsAppLink
{
    public static function to(?string $phone, string $message = ''): ?string
    {
        // wa.me only accepts international digits; a local 0XXXXXXXXX number would not open.
        $digits = PhoneNumber::normalize((string) $phone);

        if (strlen($digits) < 8) {
            return null;
        }

        return 'https://wa.me/' . $digits . ($message !== '' ? '?text=' . rawurlencode($message) : '');
    }
}
