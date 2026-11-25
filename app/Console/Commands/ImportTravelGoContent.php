<?php

namespace App\Console\Commands;

use App\Services\TravelGoImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ImportTravelGoContent extends Command
{
    protected $signature = 'travelgo:import {file? : Path to a TravelGo catalog JSON snapshot}
        {--dry-run : Validate the batch and roll back all database changes}
        {--without-media : Keep original image URLs for a test import}';

    protected $description = 'Import the pinned TravelGo catalog with stable public identifiers';

    public function handle(TravelGoImport $importer): int
    {
        $lock = Cache::lock('travelgo:catalog:import', 1800);
        if (! $lock->get()) {
            $this->error('A TravelGo catalog import is already running.');

            return self::FAILURE;
        }

        try {
            $stats = $importer->run($this->argument('file') ?? database_path('seeders/travelgo-ca35270.json'),
                mirror: ! $this->option('without-media'), dryRun: (bool) $this->option('dry-run'));
            $this->table(['Type', 'Created', 'Updated', 'Unchanged'],
                array_map(fn (string $type, array $row): array => [$type, ...array_values($row)], array_keys($stats), $stats));
            if ($this->option('dry-run')) {
                $this->info('Validation complete. The import was rolled back.');
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
