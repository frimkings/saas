<?php

namespace App\Support\Messaging;

class SmsSegments
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const GSM_EXTENDED = "^{}\[~]|€\f";

    /** Billable SMS parts: GSM-7 is 160 single / 153 per part, UCS-2 is 70 / 67. */
    public static function count(string $message): int
    {
        if ($message === '') {
            return 1;
        }

        $units = 0;
        foreach (mb_str_split($message) as $char) {
            if (mb_strpos(self::GSM_BASIC, $char) !== false) {
                $units++;
            } elseif (mb_strpos(self::GSM_EXTENDED, $char) !== false) {
                $units += 2;
            } else {
                $length = mb_strlen($message);
                return $length <= 70 ? 1 : (int) ceil($length / 67);
            }
        }

        return $units <= 160 ? 1 : (int) ceil($units / 153);
    }
}
