<?php

namespace App\Models\Concerns;

/**
 * Keeps what an item cost at the time it was sold, so profit for a past period does not
 * change when cost prices change later. A cost that is set explicitly is kept; otherwise
 * it is taken from the linked product's cost price when the item is first saved or its
 * product changes.
 */
trait RecordsUnitCost
{
    public static function bootRecordsUnitCost(): void
    {
        static::saving(function ($model): void {
            foreach ($model->unitCostSources() as $costColumn => $sources) {
                if ($model->isDirty($costColumn)) continue;
                foreach ($sources as $productColumn => $productModel) {
                    if ($model->{$productColumn} === null) continue;
                    if ($model->{$costColumn} === null || $model->isDirty($productColumn)) {
                        $model->{$costColumn} = $productModel::withTrashed()->whereKey($model->{$productColumn})->value('cost_price');
                    }
                    break;
                }
            }
        });
    }

    /**
     * Cost column => [product column => product model], first linked product wins.
     *
     * @return array<string, array<string, class-string>>
     */
    abstract protected function unitCostSources(): array;
}
