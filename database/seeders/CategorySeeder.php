<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class CategorySeeder extends Seeder
{
    private const CATEGORIES = [
        'Services' => 'service',
        'Drugs' => 'product',
        'Frames' => 'product',
        'Lenses' => 'product',
        'Others' => 'product',
    ];

    public function run(): void
    {
        $owner = User::role('Super Admin')->first() ?? User::query()->first();

        if (!$owner) {
            throw new RuntimeException('CategorySeeder requires at least one user.');
        }

        foreach (self::CATEGORIES as $name => $type) {
            $category = Category::withTrashed()->firstOrNew(['name' => $name]);

            if (!$category->exists) {
                $category->user_id = $owner->id;
            }

            $category->type = $type;
            $category->is_active = true;
            $category->save();

            if ($category->trashed()) {
                $category->restore();
            }
        }
    }
}
