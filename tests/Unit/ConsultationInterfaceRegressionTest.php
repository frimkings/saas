<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConsultationInterfaceRegressionTest extends TestCase
{
    public function test_the_structured_consultation_interface_does_not_revert_to_legacy_controls(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/doctor/patient-records-component.blade.php');

        $this->assertStringContainsString('consultation-step-nav', $view);
        $this->assertStringContainsString('odq-chip-picker', $view);
        $this->assertStringContainsString('state.odq_details', $view);
        $this->assertStringContainsString('consultation-section-review', $view);
        $this->assertStringContainsString('consultation-error-toast', $view);
        $this->assertStringContainsString('focusFirstConsultationError', $view);
        $this->assertStringContainsString('exam-action--od', $view);
        $this->assertStringContainsString('exam-action--os', $view);
        $this->assertStringNotContainsString('id="patient-records-odq"', $view);
        $this->assertStringNotContainsString('wire:click="fillNormalExamination', $view);
        $this->assertStringNotContainsString('wire:click="toggleAppointmentSection"', $view);
        $this->assertStringNotContainsString('exam-assessment', $view);
        $this->assertStringNotContainsString('Clinical classification', $view);
        $this->assertStringNotContainsString('Please fix the following errors:', $view);
    }

    public function test_expensive_tab_data_is_loaded_only_for_the_active_tab(): void
    {
        $component = file_get_contents(dirname(__DIR__, 2).'/app/Livewire/Doctor/PatientRecordsComponent.php');

        $this->assertStringContainsString("\$this->activeTab === 'history'", $component);
        $this->assertStringContainsString("\$this->activeTab === 'bills'", $component);
        $this->assertStringContainsString("\$this->activeTab === 'consultation'", $component);
        $this->assertStringContainsString("\$this->activeTab === 'refraction'", $component);
        $this->assertStringContainsString("paginate(15, ['*'], 'documentsPage')", $component);
        $this->assertStringNotContainsString('loadAvailableProducts', $component);
    }

    public function test_patient_record_assets_and_searches_remain_optimized(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/livewire/doctor/patient-records-component.blade.php');
        $component = file_get_contents($root.'/app/Livewire/Doctor/PatientRecordsComponent.php');
        $cssEntry = file_get_contents($root.'/resources/css/app.css');
        $jsEntry = file_get_contents($root.'/resources/js/app.js');

        $this->assertStringNotContainsString('<style>', $view);
        $this->assertStringNotContainsString('<script>', $view);
        $this->assertStringContainsString("@import './patient-records.css'", $cssEntry);
        $this->assertStringContainsString("import './patient-records'", $jsEntry);
        $this->assertStringContainsString("wire:model.live.debounce.400ms=\"diagnosisSearch\"", $view);
        $this->assertStringContainsString("wire:model.live.debounce.400ms=\"productSearch\"", $view);
        $this->assertStringContainsString("->where('name', 'like', \$term . '%')", $component);
        $this->assertStringContainsString("TenantCache::key('patient-records:lens-options:v2')", $component);
        $this->assertStringContainsString("->only(['id', 'family', 'display_name'])", $component);
        $this->assertStringContainsString('<template x-if="showAppointment">', $view);
        $this->assertStringContainsString('<template x-if="auditOpen">', $view);
        $this->assertStringContainsString('loadMoreAuditTrail', $component);
        $this->assertStringContainsString("config('performance.patient_records_profile')", $component);
        $quickFollowup = file_get_contents($root.'/resources/views/components/appointment-quick-followup.blade.php');
        $this->assertStringContainsString("preset('week')", $quickFollowup);
        $this->assertStringContainsString("preset('month')", $quickFollowup);
        $this->assertStringContainsString("preset('six')", $quickFollowup);
        $this->assertStringContainsString('Consultation Summary', $quickFollowup);
        $this->assertStringContainsString('Book Follow-up', $quickFollowup);
        $this->assertStringContainsString('doctorHasAppointmentConflict', $component);
    }

    public function test_the_structured_refraction_interface_is_complete_and_legacy_markup_is_removed(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/livewire/doctor/patient-records-component.blade.php');
        $partial = file_get_contents($root.'/resources/views/livewire/doctor/partials/structured-refraction-fields.blade.php');
        $component = file_get_contents($root.'/app/Livewire/Doctor/PatientRecordsComponent.php');

        $this->assertStringContainsString("@include('livewire.doctor.partials.structured-refraction-fields')", $view);
        $this->assertStringContainsString('wire:click="resetRefractionChanges"', $view);
        $this->assertStringNotContainsString('@if(false)', $view);
        $this->assertStringContainsString('1. Objective Refraction', $partial);
        $this->assertStringContainsString('2. Subjective Refraction', $partial);
        $this->assertStringContainsString('3. Dispensing', $partial);
        $this->assertStringContainsString('@if($previousRefraction)', $partial);
        $this->assertStringContainsString('loadLensProductsForRefraction', $component);
    }
}
