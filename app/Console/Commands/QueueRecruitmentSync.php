<?php

namespace App\Console\Commands;

use App\Jobs\SynchronizeRecruitment;
use Illuminate\Console\Command;

class QueueRecruitmentSync extends Command
{
    protected $signature = 'recruitment:sync {--read : Also refresh connected sources now}';

    protected $description = 'Queue an idempotent recruitment synchronization without blocking local edits';

    public function handle(): int
    {
        SynchronizeRecruitment::dispatch($this->option('read'));
        $this->info('Synchronizacja została dodana do kolejki integrations.');

        return self::SUCCESS;
    }
}
