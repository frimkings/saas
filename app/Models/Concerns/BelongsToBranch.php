<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToBranch
{
    use BelongsToClinic;

    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $builder): void {
            if (! config('tenancy.enabled')) {
                return;
            }

            $branchId = app(TenantContext::class)->branchId();
            if ($branchId === null) {
                $builder->whereRaw('1 = 0');
                return;
            }

            $builder->where($builder->qualifyColumn('branch_id'), $branchId);
        });

        static::creating(function ($model): void {
            $branchId = static::branchIdForWrite($model->clinic_id);
            if ($branchId === null) {
                throw new LogicException('A branch context is required to create '.class_basename($model).'.');
            }

            $model->branch_id = $branchId;
        });
    }

    public static function branchIdForWrite(?int $clinicId = null): ?int
    {
        $contextId = app(TenantContext::class)->branchId();
        if ($contextId !== null) {
            return $contextId;
        }

        if (config('tenancy.enabled')) {
            return null;
        }

        $clinicId ??= static::clinicIdForWrite();

        return Branch::query()
            ->where('clinic_id', $clinicId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
