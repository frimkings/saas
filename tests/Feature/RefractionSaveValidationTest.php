<?php

namespace Tests\Feature;

use App\Livewire\Doctor\PatientRecordsComponent;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RefractionSaveValidationTest extends TestCase
{
    public function test_missing_bcva_opens_subjective_section_and_explains_failure(): void
    {
        $component = new PatientRecordsComponent;
        $component->refractionSection = 'dispensing';
        $component->state = [
            'subjective_od_sphere' => -3,
            'subjective_os_sphere' => -5,
            'subjective_od_cylinder' => 0,
            'subjective_os_cylinder' => '0.00',
            'objective_od_cylinder' => 0,
            'objective_os_cylinder' => '0.00',
        ];

        try {
            $component->saveRefraction();
            $this->fail('Missing BCVA must prevent saving.');
        } catch (ValidationException $e) {
            $this->assertSame('subjective', $component->refractionSection);
            $this->assertEqualsCanonicalizing(
                ['state.subjective_od_bcva', 'state.subjective_os_bcva'],
                array_keys($e->errors())
            );
            $this->assertStringContainsString('best corrected visual acuity', $e->errors()['state.subjective_od_bcva'][0]);
        }
    }

    public function test_nonzero_cylinder_requires_axis_and_opens_objective_section(): void
    {
        $component = new PatientRecordsComponent;
        $component->refractionSection = 'dispensing';
        $component->state = [
            'objective_od_cylinder' => -4.75,
            'subjective_od_sphere' => -3,
            'subjective_os_sphere' => -5,
            'subjective_od_bcva' => '6/6',
            'subjective_os_bcva' => '6/6',
        ];

        try {
            $component->saveRefraction();
            $this->fail('Nonzero cylinder must require an axis.');
        } catch (ValidationException $e) {
            $this->assertSame('objective', $component->refractionSection);
            $this->assertSame(['state.objective_od_axis'], array_keys($e->errors()));
        }
    }
}
