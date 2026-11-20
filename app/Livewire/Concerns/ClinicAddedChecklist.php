<?php

namespace App\Livewire\Concerns;

use App\Support\Guides\ClinicAdded;
use App\Support\Guides\Guides;

/** The "clinic is now part of your account" checklist's buttons, on the Optical and Clinic dashboards. */
trait ClinicAddedChecklist
{
    public function addClinicStarterLists(): void
    {
        abort_unless(ClinicAdded::canSetUp(auth()->user()) && ClinicAdded::pending(), 403);
        app(\App\Services\ClinicAccessService::class)->assertWritable();
        $clinicId = (int) (app(\App\Support\Tenancy\TenantContext::class)->clinicId() ?: \App\Models\Setting::first()?->clinic_id);
        $added = \App\Support\ClinicStarterData::addMissing($clinicId, (int) auth()->id());
        \App\Models\AuditTrail::record('clinic.starter_lists', "Added the clinic's starter lists: {$added['diagnoses']} diagnoses, {$added['categories']} categories, {$added['lens_types']} lens types",
            null, [], $added);
        $this->dispatch('notify', type: 'success', message: "Added {$added['diagnoses']} diagnoses and {$added['categories']} product categories.");
    }

    public function hideClinicAdded(): void
    {
        abort_unless(ClinicAdded::canSetUp(auth()->user()), 403);
        Guides::mark(ClinicAdded::GUIDE, \App\Models\UserGuide::HIDDEN);
    }
}
