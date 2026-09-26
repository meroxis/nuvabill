<?php

namespace App\Console\Commands;

use App\Updates\Signature as ReleaseSignature;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:release-key {path : Where to save the secret key file (keep it outside the project)}')]
#[Description('Create the key pair used to sign Nuvabill releases (maintainers only)')]
class GenerateReleaseKey extends Command
{
    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (file_exists($path)) {
            $this->components->error("{$path} already exists. Refusing to overwrite a signing key.");

            return self::FAILURE;
        }

        $pair = ReleaseSignature::generateKeyPair();
        file_put_contents($path, $pair['secret']);
        @chmod($path, 0600);

        $this->components->info("Secret key saved to {$path}. Keep it private and add it to the NUVABILL_SIGNING_KEY GitHub secret.");
        $this->line('Public key (put it in config/nuvabill.php → updates.public_key):');
        $this->line($pair['public']);

        return self::SUCCESS;
    }
}
