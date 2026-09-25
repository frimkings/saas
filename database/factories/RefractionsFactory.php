<?php

namespace Database\Factories;

use App\Models\Consultations;
use App\Models\Refractions;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

namespace Database\Factories;

use App\Models\Consultations;
use App\Models\Refractions;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RefractionsFactory extends Factory
{
    protected $model = Refractions::class;

    public function definition(): array
{
    // Fix: We use consultation to link back to clearance/patient, 
    // we don't need clearance_id in this table.
    // $consultation = Consultations::whereDoesntHave('refraction')->inRandomOrder()->first() 
    //                 ?? Consultations::factory()->create();

    // $doctor = User::role('Doctor')->inRandomOrder()->first() 
    //           ?? User::factory()->create()->assignRole('Doctor');

    // return [
    //     'user_id'         => $doctor->id,
    //     'consultation_id' => $consultation->id, // This is your link to the patient history
        
    //     'refractionOD' => 'S: -0.25 C: -0.25 A: 60',
    //     'refractionOS' => 'S: 1.00 C: -0.25 A: 167',
        
    //     'lensType' => $this->faker->randomElement(['Single Vision', 'Bifocal', 'Progressive']),
    //     'pd'       => $this->faker->numberBetween(55, 75),
        
    //     'refractionOD_distance_va' => 'S: -0.25 C: -0.25 A: 60',
    //     'refractionOD_ADD'         => '+2.00',
    //     'refractionOD_near_va'     => 'N6',

    //     'refractionOS_distance_va' => 'S: 1.00 C: -0.25 A: 167',
    //     'refractionOS_ADD'         => '+2.00',
    //     'refractionOS_near_va'     => 'N6',

    //     'notes'           => $this->faker->sentence(),
    //     'refractionnotes' => $this->faker->sentence(),
        
      
    // ];
}



}