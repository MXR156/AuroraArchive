<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportTubeSync extends Command
{
    protected $signature = 'tubesync:import {--dry-run : Inspect TubeSync without changing either database}';

    protected $description = 'Analyse a read-only TubeSync database for a future import';

    public function handle(): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Only --dry-run is currently supported. No databases were modified.');

            return self::FAILURE;
        }
        if (! config('database.connections.tubesync.host')) {
            $this->error('Set the TUBESYNC_DB_* environment variables first.');

            return self::FAILURE;
        }
        try {
            $connection = DB::connection('tubesync');
            $pdo = $connection->getPdo();
            $database = config('database.connections.tubesync.database');
            $tables = $connection->select('SELECT TABLE_NAME AS table_name, TABLE_ROWS AS estimated_rows FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$database]);
            $this->newLine();
            $this->info('TubeSync migration analysis (read only)');
            $this->line('Server: '.$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION));
            $this->table(['Table', 'Estimated rows'], array_map(fn (object $table): array => [(string) $table->table_name, (string) ($table->estimated_rows ?? 'unknown')], $tables));
            $this->warn('No changes were made. A full importer needs a schema sample (SHOW CREATE TABLE output) for TubeSync source and media tables, plus the path mapping between its library root and MEDIA_ROOT.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('TubeSync analysis failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
