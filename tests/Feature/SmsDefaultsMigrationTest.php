<?php

namespace Tests\Feature;

use App\Models\Clinic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** On release every clinic's SMS is switched off; a rollback switches back exactly what was on. */
class SmsDefaultsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function template(Clinic $clinic, string $key, bool $on): int
    {
        return DB::table('sms_templates')->insertGetId(['clinic_id' => $clinic->id, 'key' => $key, 'label' => $key,
            'message' => 'Hello [NAME]', 'placeholders' => '[]', 'is_enabled' => $on, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_release_switches_every_sms_off_and_rollback_restores_what_was_on(): void
    {
        $first = Clinic::create(['name' => 'First', 'slug' => 'first', 'deployment_mode' => 'hosted']);
        $second = Clinic::create(['name' => 'Second', 'slug' => 'second', 'deployment_mode' => 'hosted']);
        $booking = $this->template($first, 'appointment_booking', true);
        $ready = $this->template($second, 'spectacles_ready', true);
        $birthday = $this->template($second, 'birthday_wishes', false);
        $migration = require database_path('migrations/2026_10_01_000006_switch_off_all_sms.php');

        $migration->up();
        $this->assertSame(0, DB::table('sms_templates')->where('is_enabled', true)->count(), 'Nothing is sent after release.');

        $migration->down();
        $on = fn (int $id) => (bool) DB::table('sms_templates')->where('id', $id)->value('is_enabled');
        $this->assertTrue($on($booking));
        $this->assertTrue($on($ready));
        $this->assertFalse($on($birthday), 'A message that was off stays off.');
    }
}
