<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (Schema::hasTable('lens_options')) {
            return;
        }

        Schema::create('lens_options', function (Blueprint $table) {
            $table->id();
            $table->string('family', 50);
            $table->string('display_name', 150);
            $table->timestamps();
            $table->unique(['family', 'display_name']);
            $table->index(['family', 'display_name']);
        });

        $now = now();
        $options = [
            'Single Vision' => ['SV Clear', 'SV Hard Coat', 'SV AR', 'SV Photo AR', 'SV Blue AR', 'SV Blue Block', 'SV Blue Block Photo', 'SV Special Order'],
            'Bifocal' => ['Bifocal Clear', 'Bifocal AR', 'Bifocal Photo AR', 'Bifocal Blue Block', 'Special Order Bifocal'],
            'Progressive' => ['Progressive Clear', 'Progressive AR', 'Progressive Photo AR', 'Progressive Blue Block', 'Progressive Blue Block Photo', 'Special Order Progressive'],
        ];

        foreach ($options as $family => $displayNames) {
            foreach ($displayNames as $displayName) {
                DB::table('lens_options')->insert([
                    'family' => $family,
                    'display_name' => $displayName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('lens_options');
    }
};
