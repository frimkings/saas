<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Category::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        // Get the ID of a random existing user, or create one if none exist.
        $user = User::inRandomOrder()->first() ?? User::factory()->create();

        return [
            // The 'name' field is assigned using the Factory's sequence() method.
            // This will cycle through the values based on how many records are created.
            'name' => $this->faker->text(10), // Placeholder name, will be overridden by sequence
            
            'user_id' => $user->id,
        ];
    }
    
    /**
     * Configure the model factory.
     *
     * @return $this
     */
    public function configure()
    {
        // Define the specific names you want to cycle through
        $categoryNames = [
            'Services',
            'Drugs',
            'Frames',
            'Lenses',
            'Others',
        ];
        
        // Use the Factory's sequence method to override the 'name' field
        return $this->sequence(
            // The sequence takes arrays where keys are column names and values are the cycle items
            ['name' => $categoryNames[0]], // Services
            ['name' => $categoryNames[1]], // Drugs
            ['name' => $categoryNames[2]], // Frames
            ['name' => $categoryNames[3]], // Lenses
            ['name' => $categoryNames[4]], // Others
        );
    }
}