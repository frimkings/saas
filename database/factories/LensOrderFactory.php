<?php

namespace Database\Factories;

use App\Models\LensOrder;
use App\Models\Refractions;
use App\Models\User;      // References the User (e.g., Optical Dispenser/Staff) who placed the order
use App\Models\Refraction; // References the Refraction that provides the prescription
use Illuminate\Database\Eloquent\Factories\Factory;

class LensOrderFactory extends Factory
// {
//     /**
//      * The name of the factory's corresponding model.
//      *
//      * @var string
//      */
//     protected $model = LensOrder::class;

//     /**
//      * Define the model's default state.
//      *
//      * @return array<string, mixed>
//      */
//     public function definition(): array
//     {
//         // Get an existing refraction record that hasn't been used yet for a lens order
//         // This ensures the 'refraction_id' is unique, as required by the schema.
//         $refraction = Refractions::factory()->create();

//         // Simulate frame and lens pricing
//         $framePrice = $this->faker->randomFloat(2, 50, 300);
//         $lensPrice = $this->faker->randomFloat(2, 100, 500);

//         return [
//             // Foreign Keys
//             'user_id' => User::factory(), 
//             // Ensures the refraction_id is unique
//             'refraction_id' => $refraction->id,

//             // Order Identifiers
//             // The 'order_id' is unique
//             'order_id' => $this->faker->unique()->numerify('LENS-######'),
            
//             // Frame and Pricing Details
//             'frame_model_number' => $this->faker->bothify('F-###-??'),
//             'frame_price' => $framePrice,
//             'lens_price' => $lensPrice,

//             // Dates and Notes
//             'notes' => $this->faker->optional(0.6)->sentence(10),
//             // Set the pickup date between 3 and 14 days from now
//             'pickUpDate' => $this->faker->dateTimeBetween('+3 days', '+14 days')->format('Y-m-d'), 
            
//             // softDeletes() is handled automatically by the model's setup
//             // 'created_at' and 'updated_at' are handled by $table->timestamps()
//         ];
//     }
// }