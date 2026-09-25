<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LoginLogDemoSeeder extends Seeder
{
    private const TARGET = 20_000;
    private const CHUNK  = 500;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/117.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Edge/120.0.0.0',
    ];

    private const LOCAL_IPS = [
        '192.168.1.1','192.168.1.2','192.168.1.3','192.168.1.4','192.168.1.5',
        '192.168.0.10','192.168.0.11','192.168.0.12','10.0.0.1','10.0.0.2',
        '127.0.0.1',
    ];

    public function run(): void
    {
        $existing = DB::table('login_logs')->count();
        $toSeed   = self::TARGET - $existing;

        if ($toSeed <= 0) {
            $this->command->info('LoginLogDemoSeeder: already at target (' . number_format($existing) . ' login logs).');
            return;
        }

        // Load users with role weights: Admin 20%, Doctor 35%, Cashier 30%, Secretary 15%
        $users = DB::table('users')->select('id')->get()->toArray();
        if (empty($users)) {
            $this->command->error('No users found.');
            return;
        }

        // Build weighted user pool (repeat each user proportionally)
        $userPool = [];
        foreach ($users as $idx => $user) {
            $weight = match ($idx) {
                0 => 20,  // Admin
                1 => 35,  // Doctor
                2 => 30,  // Cashier
                default => 15, // Secretary / others
            };
            for ($w = 0; $w < $weight; $w++) {
                $userPool[] = $user->id;
            }
        }
        $poolSize = count($userPool);

        $start = Carbon::create(2020, 1, 1)->timestamp;
        $end   = Carbon::now()->timestamp;

        $this->command->info("Seeding {$toSeed} login logs (target: " . number_format(self::TARGET) . ')...');
        $bar = $this->command->getOutput()->createProgressBar($toSeed);
        $bar->start();

        $seeded = 0;
        while ($seeded < $toSeed) {
            $count = min(self::CHUNK, $toSeed - $seeded);
            $rows  = [];

            for ($i = 0; $i < $count; $i++) {
                // Bias toward weekday clinic hours (7:30–17:30)
                $baseTs    = rand($start, $end);
                $loginTime = Carbon::createFromTimestamp($baseTs);

                // Shift to clinic hours: hour between 7 and 17
                $loginTime->setTime(rand(7, 17), rand(0, 59), rand(0, 59));

                $rows[] = [
                    'user_id'    => $userPool[rand(0, $poolSize - 1)],
                    'ip_address' => self::LOCAL_IPS[array_rand(self::LOCAL_IPS)],
                    'user_agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
                    'login_at'   => $loginTime->format('Y-m-d H:i:s'),
                ];
            }

            DB::table('login_logs')->insert($rows);
            $seeded += $count;
            $bar->advance($count);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('LoginLogDemoSeeder done — ' . number_format(DB::table('login_logs')->count()) . ' total login logs.');
    }
}
