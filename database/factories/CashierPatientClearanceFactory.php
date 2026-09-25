<?php

namespace Database\Factories;

use App\Models\CashierPatientClearance;
use App\Models\User;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashierPatientClearanceFactory extends Factory
{
    protected $model = CashierPatientClearance::class;

    public function definition(): array
{
    
return [
        // Find a random user who has the 'Cashier' role
        'user_id' => User::role('Cashier')->inRandomOrder()->first()?->id 
                     ?? User::factory()->afterCreating(fn($u) => $u->assignRole('Cashier')),
                     
        'patient_id' => Patient::factory(), 
        'payment_status' => $this->faker->randomElement(['Paid', 'Unpaid']),
        'doctor_status' => $this->faker->boolean(),
        'clearance_date' => $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
    ];
}
}