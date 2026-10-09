<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DESIGNS = ['stock-single-vision' => 'Single Vision', 'stock-progressive' => 'Progressive', 'stock-bifocal' => 'Bifocal'];

    /**
     * Categories say what they hold (frame, lens or accessory, and for lenses optionally the
     * lens type) instead of the app guessing from their names. Stock lenses that were filed
     * into the automatic "… Stock Lenses" categories move into the clinic's own lens category
     * of that lens type when it has one; stock and history are untouched.
     */
    public function up(): void
    {
        Schema::table('optical_categories', function (Blueprint $table) {
            $table->string('type', 20)->default('accessory')->after('name');
            $table->string('lens_type', 20)->nullable()->after('type');
        });

        foreach (DB::table('optical_categories')->get() as $category) {
            [$type, $lensType] = $this->guess($category->name.' '.$category->code);
            DB::table('optical_categories')->where('id', $category->id)->update(['type' => $type, 'lens_type' => $lensType]);
        }

        foreach (DB::table('optical_categories')->whereIn('code', array_keys(self::DESIGNS))->whereNull('deleted_at')->get() as $auto) {
            $design = self::DESIGNS[$auto->code];
            DB::table('optical_categories')->where('id', $auto->id)->update(['type' => 'lens', 'lens_type' => $design]);
            $target = DB::table('optical_categories')->where('clinic_id', $auto->clinic_id)->whereNull('deleted_at')
                ->where('id', '!=', $auto->id)->whereNotIn('code', array_keys(self::DESIGNS))
                ->where('type', 'lens')->where('lens_type', $design)->orderByDesc('is_active')->orderBy('id')->first();
            if (! $target) continue;
            DB::table('optical_products')->where('optical_category_id', $auto->id)->update(['optical_category_id' => $target->id]);
            DB::table('products')->where('optical_category_id', $auto->id)->update(['optical_category_id' => $target->id]);
            DB::table('optical_categories')->where('id', $auto->id)->update(['deleted_at' => now(), 'is_active' => false]);
        }
    }

    /** The type the app used to infer from a category's name. */
    private function guess(string $label): array
    {
        $label = strtolower($label);
        if (preg_match('/frame|eyewear|sunglass/', $label)) return ['frame', null];
        if (preg_match('/progressive|\bpal\b/', $label)) return ['lens', 'Progressive'];
        if (str_contains($label, 'bifocal')) return ['lens', 'Bifocal'];
        if (preg_match('/single[ -]?vision|\bsv[ -]/', $label)) return ['lens', 'Single Vision'];
        // Anything else was never treated as a lens or frame; managers can change it now.
        return ['accessory', null];
    }

    public function down(): void
    {
        Schema::table('optical_categories', fn (Blueprint $table) => $table->dropColumn(['type', 'lens_type']));
    }
};
