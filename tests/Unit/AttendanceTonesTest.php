<?php

namespace Tests\Unit;

use App\Services\Attendance\AttendanceAnalyzer;
use App\Support\AttendanceTones;
use PHPUnit\Framework\TestCase;

/** One set of status colours for every attendance screen and printout. */
class AttendanceTonesTest extends TestCase
{
    public function test_every_status_and_today_state_has_a_colour(): void
    {
        foreach (array_keys(AttendanceAnalyzer::STATUSES) as $status) {
            $this->assertArrayHasKey($status, AttendanceTones::STATUS, $status);
        }
        foreach (['in', 'missing', 'due', 'out', 'leave', 'holiday', 'off'] as $state) {
            $this->assertArrayHasKey(AttendanceTones::NOW[$state], AttendanceTones::STATUS, $state);
        }
        $this->assertSame('background-color:#fef3c7;color:#78350f', AttendanceTones::status('late'));
        $this->assertSame(AttendanceTones::status('none'), AttendanceTones::status('made-up'));
    }

    public function test_things_to_check_are_outlined_and_overtime_waiting_is_dashed(): void
    {
        $this->assertStringContainsString('border:1px solid #ea580c', AttendanceTones::flag('left_early'));
        $this->assertStringContainsString('dashed', AttendanceTones::flag(AttendanceTones::overtime('pending')));
        $this->assertSame('overtime', AttendanceTones::overtime('approved'));
        $this->assertSame('overtime_declined', AttendanceTones::overtime('declined'));
    }

    public function test_usual_hours_are_written_short_and_matched_to_a_shift_by_their_times(): void
    {
        $this->assertSame('8–5', \App\Support\ShiftCodes::short('08:00', '17:00'));
        $this->assertSame('7:30–4:30', \App\Support\ShiftCodes::short('07:30:00', '16:30:00'));
        $this->assertSame('9–7', \App\Support\ShiftCodes::short('21:00', '31:00'));
        $this->assertSame('21:00-07:00', \App\Support\ShiftCodes::times('21:00:00', '31:00'));
        $this->assertSame(\App\Support\ShiftCodes::times('07:00', '14:00'), \App\Support\ShiftCodes::times('07:00:00', '14:00:00'));
    }

    public function test_the_screens_and_printouts_no_longer_keep_their_own_status_colours(): void
    {
        $views = dirname(__DIR__, 2).'/resources/views/';
        foreach (['livewire/admin/attendance-component.blade.php', 'livewire/partials/my-attendance.blade.php', 'livewire/admin/partials/attendance-summary.blade.php',
            'livewire/admin/partials/attendance-today.blade.php', 'livewire/admin/partials/attendance-overtime-list.blade.php', 'pdf/attendance-sheet.blade.php'] as $file) {
            $view = file_get_contents($views.$file);
            $this->assertStringContainsString('AttendanceTones', $view, $file);
            $this->assertStringNotContainsString("'late' => 'bg-", $view, $file);
        }
    }
}
