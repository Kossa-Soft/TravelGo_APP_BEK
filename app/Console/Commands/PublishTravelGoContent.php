<?php

namespace App\Console\Commands;

use App\Services\TravelGoCatalog;
use Illuminate\Console\Command;

class PublishTravelGoContent extends Command
{
    protected $signature = 'travelgo:publish-due';

    protected $description = 'Publish scheduled TravelGo content that passes publication checks';

    public function handle(TravelGoCatalog $catalog): int
    {
        $this->info('Processed content: '.$catalog->publishDue());

        return self::SUCCESS;
    }
}
