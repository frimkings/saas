<?php

namespace App\Support\Tenancy;

class TenantCache
{
    public static function key(string $key, bool $branchSpecific = false): string
    {
        if (! config('tenancy.enabled')) {
            return $key;
        }

        $clinicId = app(TenantContext::class)->clinicId();

        $prefix = 'clinic:'.($clinicId ?? 'unresolved');
        if ($branchSpecific) {
            $prefix .= ':branch:'.(app(TenantContext::class)->branchId() ?? 'unresolved');
        }
        return $prefix.':'.$key;
    }
}
