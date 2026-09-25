<?php

if (!function_exists('currency')) {
    function currency(): string
    {
        return \App\Models\Setting::currency();
    }
}
