<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('optical_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 100);
            $table->decimal('default_markup', 6, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['clinic_id', 'code']);
            $table->unique(['clinic_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->change();
            $table->foreignId('optical_category_id')->nullable()->constrained('optical_categories')->nullOnDelete();
        });

        // Move categories created by the former optical form, leaving clinic categories untouched.
        foreach (DB::table('categories')->whereNotNull('optical_code')->orderBy('id')->cursor() as $legacy) {
            $id = DB::table('optical_categories')->insertGetId([
                'clinic_id' => $legacy->clinic_id,
                'code' => $legacy->optical_code,
                'name' => $legacy->name,
                'default_markup' => $legacy->default_markup,
                'is_active' => $legacy->is_active,
                'description' => $legacy->description,
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
                'deleted_at' => $legacy->deleted_at,
            ]);
            DB::table('products')->where('clinic_id', $legacy->clinic_id)
                ->where('category_id', $legacy->id)->update(['optical_category_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('optical_category_id');
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
        });
        Schema::dropIfExists('optical_categories');
    }
};
