<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('color', 20)->default('#6c757d');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default categories
        DB::table('expense_categories')->insert([
            ['name' => 'Staff Salaries',   'color' => '#3490dc', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Rent / Utilities', 'color' => '#f6993f', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Supplies',         'color' => '#38c172', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Equipment',        'color' => '#9561e2', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Maintenance',      'color' => '#e3342f', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Marketing',        'color' => '#ff6384', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bank Charges',     'color' => '#6574cd', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Miscellaneous',    'color' => '#6c757d', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
