<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReminderSettingsComponent;
use App\Livewire\AttentionPanelComponent;
use App\Models\Appointments;
use App\Models\AttentionAction;
use App\Models\CashierPatientClearance;
use App\Models\Clinic;
use App\Models\Consultations;
use App\Models\LensOrder;
use App\Models\Patient;
use App\Models\Refractions;
use App\Models\Setting;
use App\Models\User;
use App\Services\OwnerAlertDigestService;
use App\Services\Reminders\AttentionItems;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\GivesClinicsAccess;
use Tests\TestCase;

class AttentionRemindersTest extends TestCase
{
    use GivesClinicsAccess, DatabaseTransactions;

    private User $user;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startOfflineTrial();
        $this->travelTo(Carbon::parse('2026-10-02 10:00:00'));
        foreach (['Secretary', 'Super Admin', 'Doctor'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->user = User::factory()->create();
        $this->user->assignRole(['Secretary', 'Super Admin']);
        $this->actingAs($this->user);
        $this->patient = Patient::factory()->create(['user_id' => $this->user->id, 'name' => 'Ama Mensah', 'contact' => '0241112222']);
    }

    public function test_clinic_list_flags_appointments_and_spectacles_by_the_rules(): void
    {
        $soon = $this->appointment('11:00');
        $this->appointment('11:30', 'Arrived');                      // already here
        $this->appointment('15:00');                                 // beyond 2 hours
        $missed = $this->appointment('08:00');                       // passed, no arrival
        $late = $this->clinicOrder('Ordered', '2026-10-01');
        $due = $this->clinicOrder('In Lab', '2026-10-03');
        $this->clinicOrder('In Lab', '2026-10-10');                  // not due yet
        $uncollected = $this->clinicOrder('Ready', '2026-09-25', readyAt: '2026-09-27 09:00');
        $this->clinicOrder('Ready', '2026-10-01', readyAt: '2026-10-01 09:00'); // ready only yesterday
        $optical = $this->opticalOrder('In Production', '2026-09-30');

        $groups = app(AttentionItems::class)->groups('clinic');

        $this->assertSame([$soon->id], $groups['appt_soon']['items']->pluck('id')->all());
        $this->assertSame([$missed->id], $groups['appt_missed']['items']->pluck('id')->all());
        $this->assertSame([$late->id], $groups['order_late']['items']->pluck('id')->all());
        $this->assertSame([$due->id], $groups['order_due']['items']->pluck('id')->all());
        $this->assertSame([$uncollected->id], $groups['order_uncollected']['items']->pluck('id')->all());
        $this->assertSame('0241112222', $groups['appt_soon']['items'][0]['phone']);

        // The optical list has every job (and no appointments); the clinic list only its own orders.
        $opticalGroups = app(AttentionItems::class)->groups('optical');
        $this->assertArrayNotHasKey('appt_soon', $opticalGroups);
        $this->assertEqualsCanonicalizing([$late->id, $optical->id], $opticalGroups['order_late']['items']->pluck('id')->all());
    }

    public function test_done_clears_an_item_for_good_and_snooze_until_tomorrow(): void
    {
        $late = $this->clinicOrder('Ordered', '2026-10-01');
        $missed = $this->appointment('08:00');
        $items = app(AttentionItems::class);

        $items->act('order_late', $late->id, 'done', 'Lab says Friday');
        $items->act('appt_missed', $missed->id, 'snoozed');
        $this->assertSame([], $items->groups('clinic'));
        $this->assertDatabaseHas('attention_actions', ['subject_id' => $late->id, 'rule' => 'order_late', 'note' => 'Lab says Friday']);
        $this->assertDatabaseHas('audit_trails', ['event' => 'attention.done']);

        // Next morning the snooze is over (the appointment is a day old, so it is no longer "missed today"),
        // while the done order stays cleared.
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00'));
        $this->assertArrayNotHasKey('order_late', $items->groups('clinic'));
    }

    public function test_panel_marks_done_with_a_note_and_counts_update(): void
    {
        $uncollected = $this->clinicOrder('Ready', '2026-09-25', readyAt: '2026-09-27 09:00');
        $this->assertSame(1, app(AttentionItems::class)->counts('clinic')['orders']);

        Livewire::test(AttentionPanelComponent::class, ['line' => 'clinic', 'compact' => true])
            ->assertSee('Ready, not collected')
            ->assertSee($uncollected->order_id)
            ->call('start', 'order_uncollected', $uncollected->id, 'done')
            ->set('note', 'Called, coming Saturday')
            ->call('confirm')
            ->assertSee('Nothing needs attention right now.');

        $this->assertSame(AttentionAction::DONE, AttentionAction::where('subject_id', $uncollected->id)->value('action'));
        $this->assertSame(0, app(AttentionItems::class)->counts('clinic')['orders']);
    }

    public function test_thresholds_come_from_the_clinic_settings(): void
    {
        $this->clinicOrder('In Lab', '2026-10-04');
        $this->assertArrayNotHasKey('order_due', app(AttentionItems::class)->groups('clinic'));

        Livewire::test(ReminderSettingsComponent::class)
            ->set('due_days', 3)
            ->set('uncollected_days', 0)
            ->call('save')
            ->assertHasErrors('uncollected_days')
            ->set('uncollected_days', 5)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(3, (int) Setting::getSettings()->fresh()->reminder_due_days);
        $this->assertArrayHasKey('order_due', app(AttentionItems::class)->groups('clinic'));
    }

    public function test_start_of_shift_summary_shows_once_a_day(): void
    {
        $this->clinicOrder('Ordered', '2026-10-01');

        $this->get(route('secretary.dashboard'))->assertOk()->assertSee('Here is what needs attention today.')->assertSee('past the promised date');
        $this->get(route('secretary.dashboard'))->assertOk()->assertDontSee('Here is what needs attention today.');
        $this->get(route('attention'))->assertOk()->assertSee('Spectacles past their promised date');
    }

    public function test_owner_morning_email_lists_late_and_long_uncollected_clinic_spectacles(): void
    {
        $late = $this->clinicOrder('Ordered', '2026-09-28');
        $old = $this->clinicOrder('Ready', '2026-09-10', readyAt: '2026-09-15 09:00');   // 17 days
        $this->clinicOrder('Ready', '2026-09-25', readyAt: '2026-09-27 09:00');          // 5 days: below 14

        // The digest runs as one of the clinic's active staff.
        $clinic = Clinic::findOrFail($late->clinic_id);
        $clinic->users()->syncWithoutDetaching([$this->user->id => ['status' => 'active', 'is_default' => true]]);
        $items = collect(app(OwnerAlertDigestService::class)->current($clinic, Carbon::now()));

        $this->assertTrue($items->contains(fn ($i) => $i['section'] === 'late_pickup' && $i['key'] === 'clate' . $late->id));
        $this->assertTrue($items->contains(fn ($i) => $i['section'] === 'uncollected' && $i['key'] === 'cready' . $old->id && $i['stage'] === '14'));
        $this->assertSame(1, $items->where('section', 'uncollected')->filter(fn ($i) => str_starts_with($i['key'], 'cready'))->count());
    }

    private function appointment(string $time, string $status = 'Pending'): Appointments
    {
        return Appointments::create(['patient_id' => $this->patient->id, 'user_id' => $this->user->id, 'title' => 'Eye test',
            'scheduled_at' => Carbon::parse('2026-10-02 ' . $time), 'status' => $status]);
    }

    private function clinicOrder(string $status, string $pickUp, ?string $readyAt = null): LensOrder
    {
        // One clearance per patient per day: each order gets its own visit day.
        static $day = 0;
        $clearance = CashierPatientClearance::create(['patient_id' => $this->patient->id, 'user_id' => $this->user->id,
            'payment_status' => 'Paid', 'clearance_date' => Carbon::parse('2026-08-01')->addDays($day++)->toDateString()]);
        $consultation = Consultations::create(['patient_id' => $this->patient->id, 'user_id' => $this->user->id,
            'clearance_id' => $clearance->id, 'chiefComplaint' => 'Review']);
        $refraction = Refractions::create(['user_id' => $this->user->id, 'consultation_id' => $consultation->id,
            'refractionOD' => '-2.00', 'refractionOS' => '-1.50', 'refractionOD_distance_va' => '6/6', 'refractionOS_distance_va' => '6/6']);

        return LensOrder::create(['order_id' => 'ORD-' . strtoupper(uniqid()), 'refraction_id' => $refraction->id, 'status' => $status,
            'frame_price' => 0, 'lens_price' => 0, 'pickUpDate' => $pickUp, 'user_id' => $this->user->id,
            'ready_at' => $readyAt ? Carbon::parse($readyAt) : null]);
    }

    private function opticalOrder(string $status, string $pickUp): LensOrder
    {
        return LensOrder::create(['order_id' => 'OPT-' . strtoupper(uniqid()), 'status' => $status, 'order_source' => 'walk_in',
            'customer_name' => 'Walk In', 'customer_phone' => '0240000333', 'frame_price' => 0, 'lens_price' => 0,
            'pickUpDate' => $pickUp, 'user_id' => $this->user->id]);
    }
}
