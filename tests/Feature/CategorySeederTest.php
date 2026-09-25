<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategorySeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_seeds_the_default_categories_without_duplicates(): void
    {
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $seeder = app(CategorySeeder::class);
        $seeder->run();
        $seeder->run();

        $expected = [
            'Services' => 'service',
            'Drugs' => 'product',
            'Frames' => 'product',
            'Lenses' => 'product',
            'Others' => 'product',
        ];

        $categories = Category::query()
            ->whereIn('name', array_keys($expected))
            ->get()
            ->keyBy('name');

        $this->assertCount(count($expected), $categories);

        foreach ($expected as $name => $type) {
            $this->assertSame($type, $categories->get($name)?->type);
            $this->assertTrue($categories->get($name)?->is_active);
        }
    }
}
