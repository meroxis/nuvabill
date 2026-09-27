<?php

namespace App\Console\Commands;

use App\Marketplace\LicenseChecker;
use App\Support\WhiteLabel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:marketplace-licenses')]
#[Description('Check the license keys of themes and extensions from the marketplace, and the White-label license')]
class CheckMarketplaceLicenses extends Command
{
    public function handle(LicenseChecker $checker, WhiteLabel $whiteLabel): int
    {
        $checker->checkAll();
        $whiteLabel->check();
        $this->components->info('Marketplace licenses checked.');

        return self::SUCCESS;
    }
}
