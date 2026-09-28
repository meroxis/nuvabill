<?php

namespace App\Console\Commands;

use App\Marketplace\LicenseChecker;
use App\Marketplace\MarketplaceClient;
use App\Support\WhiteLabel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:marketplace-licenses')]
#[Description('Check the license keys of themes and extensions from the marketplace, and the White-label license, and look for new versions')]
class CheckMarketplaceLicenses extends Command
{
    public function handle(LicenseChecker $checker, WhiteLabel $whiteLabel, MarketplaceClient $client): int
    {
        $checker->checkAll();
        $whiteLabel->check();
        // So the sidebar shows new versions even when nobody opens the marketplace.
        $client->catalog(fresh: true);
        $this->components->info('Marketplace licenses checked.');

        return self::SUCCESS;
    }
}
