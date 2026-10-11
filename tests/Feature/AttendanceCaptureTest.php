<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The capture address records what a terminal sends, in any of its formats, and keeps no photo. */
class AttendanceCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT = '{"eventType":"AccessControllerEvent","AccessControllerEvent":{"employeeNoString":"12","serialNo":40}}';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.attendance.capture_token', 'secret-token');
    }

    public function test_it_is_off_without_a_token_and_rejects_a_wrong_one(): void
    {
        $this->postJson('/api/v1/attendance/capture/wrong')->assertNotFound();

        config()->set('services.attendance.capture_token', null);
        $this->postJson('/api/v1/attendance/capture/secret-token')->assertNotFound();

        $this->assertSame(0, DB::table('attendance_captures')->count());
    }

    public function test_it_records_a_json_body(): void
    {
        $this->call('POST', '/api/v1/attendance/capture/secret-token', [], [], [], ['CONTENT_TYPE' => 'application/json'], self::EVENT)
            ->assertOk();

        $capture = DB::table('attendance_captures')->first();
        $this->assertSame('POST', $capture->method);
        $this->assertSame('application/json', $capture->content_type);
        $this->assertSame(self::EVENT, $capture->body);
    }

    public function test_it_records_multipart_fields_and_describes_the_photo_without_keeping_it(): void
    {
        $this->post('/api/v1/attendance/capture/secret-token', [
            'event_log' => self::EVENT,
            'Picture' => UploadedFile::fake()->image('face.jpg'),
        ])->assertOk();

        $capture = DB::table('attendance_captures')->first();
        $this->assertSame(self::EVENT, json_decode($capture->fields, true)['event_log']);
        $photo = json_decode($capture->files, true)['Picture'];
        $this->assertSame('face.jpg', $photo['name']);
        $this->assertGreaterThan(0, $photo['bytes']);
        $this->assertSame(['name', 'type', 'bytes'], array_keys($photo));
    }

    public function test_it_records_xml_and_leaves_out_credentials(): void
    {
        $xml = '<EventNotificationAlert><eventType>AccessControllerEvent</eventType></EventNotificationAlert>';
        $this->call('POST', '/api/v1/attendance/capture/secret-token', [], [], [], [
            'CONTENT_TYPE' => 'application/xml', 'HTTP_AUTHORIZATION' => 'Basic YWRtaW46cGFzcw==',
        ], $xml)->assertOk();

        $capture = DB::table('attendance_captures')->first();
        $this->assertSame($xml, $capture->body);
        $this->assertArrayNotHasKey('authorization', json_decode($capture->headers, true));
    }

    public function test_only_the_latest_captures_are_kept(): void
    {
        $row = ['method' => 'POST', 'secure' => false, 'headers' => '{}', 'received_at' => now()];
        DB::table('attendance_captures')->insert(array_fill(0, 205, $row));

        $this->postJson('/api/v1/attendance/capture/secret-token', ['n' => 1])->assertOk();

        $this->assertSame(200, DB::table('attendance_captures')->count());
        $this->assertSame(1, DB::table('attendance_captures')->where('body', '{"n":1}')->count());
    }

    public function test_the_command_prints_and_clears_captures(): void
    {
        $this->artisan('attendance:captures')->expectsOutputToContain('Nothing captured yet.')->assertSuccessful();

        $this->postJson('/api/v1/attendance/capture/secret-token', ['employeeNoString' => '12'])->assertOk();
        $this->artisan('attendance:captures')->expectsOutputToContain('employeeNoString')->assertSuccessful();

        $this->artisan('attendance:captures --clear')->assertSuccessful();
        $this->assertSame(0, DB::table('attendance_captures')->count());
    }
}
