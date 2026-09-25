<?php

namespace App\Models\Concerns;

use App\Models\Clinic;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToClinic
{
    public static function bootBelongsToClinic(): void
    {
        static::addGlobalScope('clinic', function (Builder $builder): void {
            if (! config('tenancy.enabled')) {
                return;
            }

            $clinicId = app(TenantContext::class)->clinicId();
            if ($clinicId === null) {
                $builder->whereRaw('1 = 0');
                return;
            }

            $builder->where($builder->qualifyColumn('clinic_id'), $clinicId);
        });

        static::creating(function ($model): void {
            $clinicId = static::clinicIdForWrite();
            if ($clinicId === null) {
                throw new LogicException('A clinic context is required to create '.class_basename($model).'.');
            }

            // Never trust a client-supplied clinic_id.
            $model->clinic_id = $clinicId;
        });
    }

    public static function clinicIdForWrite(): ?int
    {
        $contextId = app(TenantContext::class)->clinicId();
        if ($contextId !== null) {
            return $contextId;
        }

        if (config('tenancy.enabled')) {
            return null;
        }

        return Clinic::query()->where('status', 'active')->orderBy('id')->value('id')
            ?? Clinic::query()->orderBy('id')->value('id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
