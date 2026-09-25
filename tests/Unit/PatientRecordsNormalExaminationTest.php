<?php

namespace Tests\Unit;

use App\Livewire\Doctor\PatientRecordsComponent;
use PHPUnit\Framework\TestCase;

class PatientRecordsNormalExaminationTest extends TestCase
{
    public function test_it_fills_empty_normal_findings_for_both_eyes_without_overwriting_existing_values(): void
    {
        $component = new PatientRecordsComponent();
        $component->state = [
            'corneaOD' => 'Scar',
            'vaOD6m' => '6/6',
            'cdrOD' => '0.4',
            'IOPOD' => '15',
        ];

        $component->fillNormalExamination();

        $this->assertSame('Scar', $component->state['corneaOD']);
        $this->assertSame('Clear', $component->state['corneaOS']);
        $this->assertSame('White and quiet', $component->state['conjunctivaOD']);
        $this->assertSame('Deep and quiet', $component->state['acOS']);
        $this->assertSame('6/6', $component->state['vaOD6m']);
        $this->assertSame('0.4', $component->state['cdrOD']);
        $this->assertSame('15', $component->state['IOPOD']);
        $this->assertArrayNotHasKey('vaOS6m', $component->state);
        $this->assertArrayNotHasKey('cdrOS', $component->state);
        $this->assertArrayNotHasKey('IOPOS', $component->state);
    }

    public function test_it_can_fill_only_one_eye(): void
    {
        $component = new PatientRecordsComponent();

        $component->fillNormalExamination('od');

        $this->assertSame('Normal', $component->state['lidsOD']);
        $this->assertSame('Round, regular and reactive', $component->state['pupilOD']);
        $this->assertArrayNotHasKey('lidsOS', $component->state);
        $this->assertArrayNotHasKey('pupilOS', $component->state);
    }

    public function test_it_clears_descriptive_findings_for_one_eye_without_clearing_measurements(): void
    {
        $component = new PatientRecordsComponent();
        $component->state = [
            'lidsOD' => 'Normal',
            'lidsOS' => 'Normal',
            'corneaOD' => 'Scar',
            'vaOD6m' => '6/6',
            'cdrOD' => '0.4',
            'IOPOD' => '15',
        ];

        $component->clearExaminationFindings('od');

        $this->assertSame('', $component->state['lidsOD']);
        $this->assertSame('', $component->state['corneaOD']);
        $this->assertSame('Normal', $component->state['lidsOS']);
        $this->assertSame('6/6', $component->state['vaOD6m']);
        $this->assertSame('0.4', $component->state['cdrOD']);
        $this->assertSame('15', $component->state['IOPOD']);
    }

    public function test_only_triple_plus_eye_pain_adds_the_structured_severe_pain_red_flag(): void
    {
        $component = new PatientRecordsComponent();
        $key = md5('eye pain');
        $component->state = [
            'odq' => ['Eye Pain'],
            'odq_details' => [
                $key => ['symptom' => 'Eye Pain', 'severity' => '++', 'eye' => 'OU'],
            ],
        ];

        $this->assertNotContains('Severe eye pain (+++)', $component->getUrgentReferralReasonsProperty());

        $component->state['odq_details'][$key]['severity'] = '+++';

        $this->assertContains('Severe eye pain (+++)', $component->getUrgentReferralReasonsProperty());
    }

}
