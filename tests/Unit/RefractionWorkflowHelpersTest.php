<?php

namespace Tests\Unit;

use App\Livewire\Doctor\PatientRecordsComponent;
use PHPUnit\Framework\TestCase;

class RefractionWorkflowHelpersTest extends TestCase
{
    public function test_objective_results_can_seed_subjective_refraction(): void
    {
        $component = new PatientRecordsComponent();
        $component->state = [
            'objective_od_sphere' => '-2.00', 'objective_od_cylinder' => '-0.50', 'objective_od_axis' => 90, 'objective_od_va' => '6/9',
            'objective_os_sphere' => '-1.50', 'objective_os_cylinder' => '-0.25', 'objective_os_axis' => 80, 'objective_os_va' => '6/9',
        ];

        $component->copyObjectiveToSubjective();

        $this->assertSame('-2.00', $component->state['subjective_od_sphere']);
        $this->assertSame(80, $component->state['subjective_os_axis']);
        $this->assertSame('6/9', $component->state['subjective_od_bcva']);
        $this->assertSame('subjective', $component->refractionSection);
    }

    public function test_presets_apply_to_both_eyes(): void
    {
        $component = new PatientRecordsComponent();
        $component->state = ['subjective_od_add' => '2.00'];

        $component->applyRefractionPreset('plano');
        $component->applyRefractionPreset('equal_add');

        $this->assertSame('0.00', $component->state['subjective_od_sphere']);
        $this->assertSame('0.00', $component->state['subjective_os_sphere']);
        $this->assertSame('2.00', $component->state['subjective_os_add']);
    }

    public function test_clinical_warnings_flag_unusual_or_incomplete_values(): void
    {
        $component = new PatientRecordsComponent();
        $component->state = [
            'subjective_od_cylinder' => '-1.00',
            'subjective_od_add' => '2.00',
            'objective_od_sphere' => '-5.00',
            'subjective_od_sphere' => '-2.00',
            'pd' => 90,
        ];

        $warnings = $component->getRefractionWarningsProperty();

        $this->assertCount(4, $warnings);
    }

    public function test_axis_is_cleared_when_cylinder_is_removed(): void
    {
        $component = new PatientRecordsComponent();
        $component->state = ['subjective_od_axis' => 90];

        $component->updatedState('0.00', 'subjective_od_cylinder');

        $this->assertNull($component->state['subjective_od_axis']);
    }
}
