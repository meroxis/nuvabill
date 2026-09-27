<?php

namespace App\Console\Commands;

use App\Marketplace\Store\SigningKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('nuvabill:marketplace-key {--show : Only print the public key of the existing key}')]
#[Description('Create the marketplace store signing key (run once, on the store server) and print its public key')]
class MarketplaceKey extends Command
{
    public function handle(SigningKey $key): int
    {
        try {
            $public = $this->option('show') ? $key->publicKey() : $key->generate();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        file_put_contents(storage_path('app/private/marketplace-public.key'), $public);
        $this->components->info('Public key (put it in config/nuvabill.php → marketplace.public_key):');
        $this->line($public);

        return self::SUCCESS;
    }
}
