<?php

namespace App\Console\Commands;

use App\Marketplace\LicenseChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:marketplace-licenses')]
#[Description('Check the license keys of themes and extensions from the marketplace')]
class CheckMarketplaceLicenses extends Command
{
    public function handle(LicenseChecker $checker): int
    {
        $checker->checkAll();
        $this->components->info('Marketplace licenses checked.');

        return self::SUCCESS;
    }
}
