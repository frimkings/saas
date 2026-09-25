<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('lens_orders')->whereNotNull('notes')->orderBy('id')->cursor() as $order) {
            $docket = json_decode($order->notes, true);
            $oldId = is_array($docket) ? data_get($docket, 'lens_details.category_id') : null;
            if (! $oldId) continue;
            $legacy = DB::table('categories')->where('id', $oldId)
                ->where('clinic_id', $order->clinic_id)->whereNotNull('optical_code')->first();
            if (! $legacy) continue;
            $new = DB::table('optical_categories')->where('clinic_id', $order->clinic_id)
                ->where('code', $legacy->optical_code)->first();
            if (! $new) continue;
            $docket['lens_details']['category_id'] = $new->id;
            $docket['lens_details']['category_name'] = $new->name;
            DB::table('lens_orders')->where('id', $order->id)->update(['notes' => json_encode($docket, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Historical docket snapshots retain the readable category name.
    }
};
