<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Prints what the attendance terminal has sent to the capture address (AttendanceCaptureController). */
class ShowAttendanceCapturesCommand extends Command
{
    protected $signature = 'attendance:captures {--limit=5 : How many of the latest to show} {--clear : Delete every capture}';
    protected $description = 'Show the latest requests an attendance terminal sent to the capture address';

    public function handle(): int
    {
        if ($this->option('clear')) {
            $this->info(DB::table('attendance_captures')->delete().' capture(s) deleted.');
            return self::SUCCESS;
        }

        $captures = DB::table('attendance_captures')->orderByDesc('id')->limit(max(1, (int) $this->option('limit')))->get();
        if ($captures->isEmpty()) {
            $this->info('Nothing captured yet.');
            return self::SUCCESS;
        }

        foreach ($captures->reverse() as $capture) {
            $this->line(str_repeat('=', 70));
            $this->info("#{$capture->id}  {$capture->received_at} UTC  {$capture->method}  ".($capture->secure ? 'HTTPS' : 'HTTP')."  from {$capture->ip}");
            $this->line('Content-Type: '.($capture->content_type ?? '(none)'));
            $this->line('Headers: '.$this->pretty($capture->headers));
            $this->line('Fields: '.$this->pretty($capture->fields));
            $this->line('Files: '.$this->pretty($capture->files));
            $this->line('Body: '.($capture->body ?? '(empty)'));
        }

        return self::SUCCESS;
    }

    private function pretty(?string $json): string
    {
        $decoded = json_decode((string) $json, true);

        return $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '(none)';
    }
}
