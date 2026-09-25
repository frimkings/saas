<?php

namespace App\Support\Tenancy;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use LogicException;

class TenantStorage
{
    public static function patientDocuments(Patient $patient): string
    {
        $clinic = self::clinic();
        return 'clinics/'.$clinic->uuid.'/patients/'.$patient->id.'/documents';
    }

    public static function branch(string $folder): string
    {
        return 'clinics/'.self::clinic()->uuid.'/branches/'.self::branchModel()->uuid.'/'.trim($folder, '/');
    }

    public static function branding(): string
    {
        return 'clinics/'.self::clinic()->uuid.'/branding';
    }

    private static function clinic(): Clinic
    {
        $clinic = app(TenantContext::class)->clinic();

        if ($clinic) {
            return $clinic;
        }

        if (config('tenancy.enabled')) {
            throw new LogicException('Clinic context is required for tenant file storage.');
        }

        return Clinic::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    }

    private static function branchModel(): Branch
    {
        $branch = app(TenantContext::class)->branch();

        if ($branch) {
            return $branch;
        }

        if (config('tenancy.enabled')) {
            throw new LogicException('Branch context is required for tenant file storage.');
        }

        return Branch::query()->where('clinic_id', self::clinic()->id)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('id')->firstOrFail();
    }
}
