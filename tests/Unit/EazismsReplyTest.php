<?php

namespace Tests\Unit;

use App\Services\Messaging\Drivers\EazismsDriver;
use PHPUnit\Framework\TestCase;

/** EazismsPro reports a sent SMS in more than one shape; a rejection must still read as a failure. */
class EazismsReplyTest extends TestCase
{
    private function read(string $raw): array
    {
        $body = json_decode($raw, true);

        return EazismsDriver::interpret(is_array($body) ? $body : [], $raw);
    }

    public function test_every_success_reply_counts_as_sent(): void
    {
        foreach ([
            '{"status":"success","message_id":"abc123"}',
            '{"code":"ok","message":"Successfully Sent","data":{"id":"m-9"}}',
            '{"status":"ok","message":"Successfully Sent"}',
            '{"message":"Successfully Sent"}',
            '"Successfully Sent"',
            'Successfully Sent',
        ] as $raw) {
            $this->assertTrue($this->read($raw)['success'], "Should count as sent: {$raw}");
        }

        $this->assertSame('abc123', $this->read('{"status":"success","message_id":"abc123"}')['message_id']);
        $this->assertSame('m-9', $this->read('{"code":"ok","message":"Successfully Sent","data":{"id":"m-9"}}')['message_id']);
    }

    public function test_rejections_are_failures_with_the_gateways_reason(): void
    {
        $this->assertSame(['success' => false, 'error' => 'Invalid Sender id'], $this->read('{"status":"error","message":"Invalid Sender id"}'));
        $this->assertSame(['success' => false, 'error' => 'Invalid Sender id'], $this->read('{"code":"1003","message":"Invalid Sender id"}'));
        $this->assertSame(['success' => false, 'error' => 'Insufficient balance'], $this->read('Insufficient balance'));
        $this->assertFalse($this->read('{"status":"success","error":"Number blacklisted"}')['success']);
        $this->assertSame('Unknown gateway response.', $this->read('')['error']);
    }
}
