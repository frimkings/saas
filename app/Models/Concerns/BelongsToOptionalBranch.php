<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;

trait BelongsToOptionalBranch
{
    protected static function bootBelongsToOptionalBranch(): void
    {
        static::creating(function ($model) {
            $context = app(TenantContext::class);
            if ($context->resolved()) {
                $model->branch_id = $context->branchId();
            } elseif (! $model->branch_id) {
                $model->branch_id = Branch::query()->where('clinic_id', $model->clinic_id)
                    ->orderByDesc('is_default')->orderBy('id')->value('id');
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
