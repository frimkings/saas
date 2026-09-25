<?php

namespace Database\Factories;

use App\Models\Appointments;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentsFactory extends Factory
{
    protected $model = Appointments::class;

    public function definition()
    {
        return [
            'patient_id' => Patient::factory(), // Creates a patient automatically
            'user_id' => User::first()?->id ?? User::factory(), // Uses existing user or creates one
            'title' => $this->faker->randomElement([
                'Dental Checkup', 
                'General Consultation', 
                'Follow-up Visit', 
                'Vaccination', 
                'Physical Therapy'
            ]),
            'notes' => $this->faker->sentence(),
            // Generates dates within the current month for testing the calendar view
            'scheduled_at' => $this->faker->dateTimeBetween('first day of this month', 'last day of this month'),
            'status' => $this->faker->randomElement(['scheduled', 'completed', 'cancelled']),
        ];
    }
}