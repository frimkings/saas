<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use App\Models\Category; // 1. IMPORT THE CATEGORY MODEL
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $cost = $this->faker->randomFloat(2, 5.00, 200.00);

        return [
            'user_id'          => User::factory(),
            'category_id'      => Category::factory(),
            'name'             => $this->faker->unique()->words(3, true),
            'batch_number'     => 'BTH' . strtoupper(bin2hex(random_bytes(3))),
            'quantity'         => $this->faker->numberBetween(1, 500),
            'cost_price'       => $cost,
            'selling_price'    => round($cost * $this->faker->randomFloat(2, 1.1, 2.0), 2),
            'manufacture_date' => now()->subMonths(rand(6, 24))->format('Y-m-d'),
            'expiry_date'      => now()->addMonths(rand(6, 36))->format('Y-m-d'),
        ];
    }
}