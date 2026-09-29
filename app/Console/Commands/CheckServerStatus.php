<?php

namespace App\Console\Commands;

use App\Support\ServerMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:server-status')]
#[Description('Check that every switched-on server answers, for the network status page and staff alerts')]
class CheckServerStatus extends Command
{
    public function handle(ServerMonitor $monitor): int
    {
        $result = $monitor->checkAll();

        $this->components->info("Servers checked: {$result['checked']}, down: {$result['down']}");

        return self::SUCCESS;
    }
}
