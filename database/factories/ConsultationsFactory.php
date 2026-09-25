<?php

namespace Database\Factories;

use App\Models\Consultations;
use App\Models\User;
use App\Models\CashierPatientClearance;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsultationsFactory extends Factory
{
    protected $model = Consultations::class;

    public function definition(): array
    {
        $clearance = CashierPatientClearance::inRandomOrder()->first()
                     ?? CashierPatientClearance::factory()->create();

        $doctor = User::role('Doctor')->inRandomOrder()->first()
                  ?? User::factory()->create()->assignRole('Doctor');

        return [
            'user_id'      => $doctor->id,
            'patient_id'   => $clearance->patient_id,
            'clearance_id' => $clearance->id,

            'chiefComplaint' => $this->faker->sentence(5),
            'others'         => $this->faker->optional(0.3)->sentence(10),

            'vaOD6m' => $this->faker->randomElement(['6/6', '6/9', '6/12', '6/18', '6/24', '6/60']),
            'vaOS6m' => $this->faker->randomElement(['6/6', '6/9', '6/12', '6/18', '6/24', '6/60']),

            'lidsOD'        => $this->faker->randomElement(['Normal', 'Mild Ptosis', 'Blepharitis']),
            'lidsOS'        => $this->faker->randomElement(['Normal', 'Mild Ptosis', 'Blepharitis']),
            'conjunctivaOD' => $this->faker->randomElement(['Clear', 'Injection', 'Pale']),
            'conjunctivaOS' => $this->faker->randomElement(['Clear', 'Injection', 'Pale']),
            'corneaOD'      => $this->faker->randomElement(['Clear', 'Punctate Epitheliopathy', 'Scar']),
            'corneaOS'      => $this->faker->randomElement(['Clear', 'Punctate Epitheliopathy', 'Scar']),
            'irisOD'        => 'Normal',
            'irisOS'        => 'Normal',
            'pupilOD'       => 'PERRLA',
            'pupilOS'       => 'PERRLA',
            'lensOD'        => $this->faker->randomElement(['Clear', 'Trace NS', 'Grade 2 Cataract']),
            'lensOS'        => $this->faker->randomElement(['Clear', 'Trace NS', 'Grade 2 Cataract']),

            'vitreousOD' => 'Clear',
            'vitreousOS' => 'Clear',
            'fundusOD'   => $this->faker->randomElement(['Flat and Attached', 'Normal C/D', 'No Hemorrhage']),
            'fundusOS'   => $this->faker->randomElement(['Flat and Attached', 'Normal C/D', 'No Hemorrhage']),
            'cdrOD'      => $this->faker->randomFloat(2, 0.2, 0.4),
            'cdrOS'      => $this->faker->randomFloat(2, 0.2, 0.4),

            'IOPOD' => $this->faker->numberBetween(10, 21),
            'IOPOS' => $this->faker->numberBetween(10, 21),

            'nextvisit' => $this->faker->optional(0.7)->dateTimeBetween('now', '+6 months')?->format('Y-m-d'),
            'notes'     => $this->faker->optional(0.8)->paragraph(2),
            'review'    => $this->faker->optional(0.5)->sentence(10),
            'drug_id'   => null,
        ];
    }
}
