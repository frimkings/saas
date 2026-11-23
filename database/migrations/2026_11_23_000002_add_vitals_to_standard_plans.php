<?php

use App\Support\Feature;
use App\Support\PlanProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vitals is a Standard-and-up clinical feature (23 Nov 2026). Plans and subscriptions that chose the
 * new tiers (they carry the CLINIC_TIERS_CHOSEN marker) hold an explicit feature list, so this adds
 * `vitals` to the Standard-or-above ones — spotted by APPOINTMENTS, which Standard introduces. Older
 * plans without the marker already get every clinic feature, so they are left alone. Starter is left
 * alone too. New subscriptions pick it up from the plan templates (also updated here).
 */
return new class extends Migration {
    public function up(): void
    {
        $this->addVitals('subscription_plans', 'features');
        $this->addVitals('clinic_subscriptions', 'feature_snapshot');
    }

    private function addVitals(string $table, string $column): void
    {
        DB::table($table)->select('id', $column)->orderBy('id')->chunkById(200, function ($rows) use ($table, $column) {
            foreach ($rows as $row) {
                $features = json_decode($row->$column ?? '[]', true);
                if (! is_array($features) || in_array('*', $features, true)) {
                    continue;
                }
                $standardUp = in_array(Feature::CLINIC_TIERS_CHOSEN, $features, true)
                    && in_array(Feature::APPOINTMENTS, $features, true)
                    && ! in_array(Feature::VITALS, $features, true);
                if ($standardUp) {
                    $features[] = Feature::VITALS;
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode(array_values($features))]);
                }
            }
        });
    }

    public function down(): void
    {
        foreach (['subscription_plans' => 'features', 'clinic_subscriptions' => 'feature_snapshot'] as $table => $column) {
            DB::table($table)->select('id', $column)->orderBy('id')->chunkById(200, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    $features = json_decode($row->$column ?? '[]', true);
                    if (is_array($features) && in_array(Feature::VITALS, $features, true)) {
                        DB::table($table)->where('id', $row->id)
                            ->update([$column => json_encode(array_values(array_diff($features, [Feature::VITALS])))]);
                    }
                }
            });
        }
    }
};
