<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncDesktopDatabase extends Command
{
    protected $signature = 'clinic:sync-desktop';
    protected $description = 'Copies all data records from Laragon MySQL directly into the NativePHP SQLite database file.';

    public function handle()
    {
        $this->info('Starting database sync from Laragon MySQL to NativePHP...');

        // 1. Fetch all tables present in your active Laragon database
        $tables = DB::connection('mysql')->getDoctrineSchemaManager()->listTableNames();

        // 2. Disable foreign key constraints on SQLite to prevent order insertion conflicts
        DB::connection('nativephp')->statement('PRAGMA foreign_keys = OFF;');

        foreach ($tables as $table) {
            // Skip the framework internal tracking layouts
            if (in_array($table, ['failed_jobs'])) {
                continue;
            }

            $this->comment("Syncing table: {$table}");

            // Pull live clinical data from your Laragon MySQL server
            $rows = DB::connection('mysql')->table($table)->get();

            if ($rows->isEmpty()) {
                continue;
            }

            // If the table tracking schema doesn't exist on SQLite yet, create it dynamically
            if (!Schema::connection('nativephp')->hasTable($table)) {
                // Duplicate the structure by creating an empty target table layout framework
                Schema::connection('nativephp')->create($table, function ($blueprint) use ($table) {
                    $columns = Schema::connection('mysql')->getColumnListing($table);
                    foreach ($columns as $column) {
                        // Create a generic fallback text structure to handle data fields flexibly
                        $blueprint->text($column)->nullable();
                    }
                });
            } else {
                // Clear any partial table remnants before fresh injection
                DB::connection('nativephp')->table($table)->truncate();
            }

            // Chunk data and pipe it directly into your NativePHP SQLite file
            foreach ($rows->chunk(100) as $chunk) {
                $arrayChunk = json_decode(json_encode($chunk), true);
                DB::connection('nativephp')->table($table)->insert($arrayChunk);
            }
        }

        // 3. Re-enable foreign key rules safely
        DB::connection('nativephp')->statement('PRAGMA foreign_keys = ON;');

        $this->info('Success! Your entire eye clinic database has been successfully copied into NativePHP.');
        return Command::SUCCESS;
    }
}
