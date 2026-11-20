<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Start Optical Order" on the clinic's Spectacle Orders page (20 Nov 2026): it sent clinic plans to a page they cannot open. */
class SpectaclesStartOrderTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\ClinicOnPlan;

    public function test_on_a_clinic_plan_the_button_opens_the_refractions_needing_an_order(): void
    {
        $this->ownerOn('clinic-plus');

        $page = $this->get(route('secretary.spectacles'))->assertOk()->assertSee('Start Optical Order');
        $page->assertDontSee(route('optical.orders.create'), false);
        $page->assertSee("wire:click=\"setRecordType('refractions')\" class=\"so-btn text-white\"", false);

        // The optical shop's order page is not on this plan.
        $this->assertContains($this->get(route('optical.orders.create'))->getStatusCode(), [302, 403]);
    }
}
