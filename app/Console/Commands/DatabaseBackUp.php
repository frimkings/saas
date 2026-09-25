<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;

class DatabaseBackUp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:name';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // $filename = "backup-" . Carbon::now()->format('Y-m-d') . ".gz";
        $filename = "backup-" . Carbon::now()->format('d-M-Y') . ".sql";


        $command = "mysqldump --user=" . escapeshellarg(env('DB_USERNAME'))
            . " --password=" . escapeshellarg(env('DB_PASSWORD'))
            . " --host=" . escapeshellarg(env('DB_HOST'))
            . " " . escapeshellarg(env('DB_DATABASE'))
            . " | gzip > " . escapeshellarg(storage_path('app/backup/' . $filename));

        $output    = [];
        $returnVar = 0;

        exec($command, $output, $returnVar);

    }
}
