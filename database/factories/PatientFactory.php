<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        $gender = $this->faker->randomElement(['Male', 'Female']);

        return [
            'user_id'    => User::factory(),
            'name'       => $this->faker->name($gender),
            'pxnumber'   => 'PX-' . strtoupper(bin2hex(random_bytes(4))),
            'contact'    => $this->faker->phoneNumber(),
            'gender'     => $gender,
            'dob'        => $this->faker->dateTimeBetween('-85 years', '-18 years')->format('Y-m-d'),
            'address'    => $this->faker->address(),
            'occupation' => $this->faker->jobTitle(),
        ];
    }
}
