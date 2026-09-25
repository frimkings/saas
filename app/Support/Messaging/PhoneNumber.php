<?php

namespace App\Support\Messaging;

class PhoneNumber
{
    /** Normalize to plain international digits (no "+"); local Ghana numbers become 233XXXXXXXXX. */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '233' . substr($digits, 1);
        }

        return $digits;
    }
}
