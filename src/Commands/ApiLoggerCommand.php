<?php

namespace Ameax\ApiLogger\Commands;

use Illuminate\Console\Command;

class ApiLoggerCommand extends Command
{
    public $signature = 'apilogger';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
