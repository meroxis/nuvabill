<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\PendingCheck;
use App\Marketplace\Store\SigningKey;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The marketplace store only: where the key that signs marketplace packages is kept and who
 * can read it. Every Nuvabill site trusts packages signed with this key, so it must stay with
 * the store alone. The checks only look; they never move or change the key.
 */
class SigningKeyChecks extends CheckGroup
{
    public function __construct(private SigningKey $key) {}

    public function key(): string
    {
        return 'signing-key';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Marketplace signing key';
    }

    public function description(): string
    {
        return 'Where the key that signs marketplace packages is kept, and who can read it.';
    }

    public function icon(): string
    {
        return 'lock';
    }

    public function run(): array
    {
        return [
            $this->guarded($this->check('signing_key.readers', 'Only you can read the marketplace signing key', weight: 5), $this->readers(...)),
            $this->guarded($this->check('signing_key.place', 'The marketplace signing key is kept outside the Nuvabill folder', weight: 3), $this->place(...)),
            $this->guarded($this->check('signing_key.trusted', 'Nuvabill sites trust the marketplace signing key', weight: 5), $this->trusted(...)),
        ];
    }

    /**
     * @param  callable(PendingCheck): CheckResult  $run
     */
    private function guarded(PendingCheck $check, callable $run): CheckResult
    {
        try {
            return $run($check);
        } catch (Throwable $exception) {
            report($exception);

            return $check->skipped('This check could not finish: :error', ['error' => Str::limit($exception->getMessage(), 120)]);
        }
    }

    private function readers(PendingCheck $check): CheckResult
    {
        if (! $this->key->exists()) {
            return $check->skipped('There is no signing key on this server yet.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $check->skipped('This server does not use Unix file permissions.');
        }

        $mode = fileperms($this->key->path()) & 0777;

        if (($mode & 0o077) === 0) {
            return $check->passed(':mode', ['mode' => sprintf('%o', $mode)]);
        }

        return $check->urgent('Other accounts on the server can read or change it (now :mode, should be 600)', ['mode' => sprintf('%o', $mode)],
            advice: 'Anyone who copies this key can sign packages that every Nuvabill site installs. Set the file to 600 (chmod 600), so only the account that runs the store can read it.',
            items: [['label' => $this->key->path(), 'value' => sprintf('%o → 600', $mode), 'mono' => true, 'status' => 'urgent']],
        );
    }

    private function place(PendingCheck $check): CheckResult
    {
        if (! $this->key->exists()) {
            return $check->skipped('There is no signing key on this server yet.');
        }

        $path = $this->normal((string) (realpath($this->key->path()) ?: $this->key->path()));
        $item = [['label' => $path, 'mono' => true]];

        if ($this->isInside($path, public_path())) {
            return $check->urgent('The key is in the public folder', [],
                advice: 'Files in the public folder can be downloaded by anyone who knows the name. Move the key file out of it now, to a folder only your account can read, and set NUVABILL_MARKETPLACE_SIGNING_KEY_PATH in the .env file to the new place.',
                items: [$item[0] + ['status' => 'urgent']],
            );
        }

        if ($this->isInside($path, base_path())) {
            return $check->warning('The key is inside the Nuvabill folder', [],
                advice: 'A whole-site backup copies every file in the Nuvabill folder, so each backup holds the key too. Move the key file to a folder outside it that only your account can read, set NUVABILL_MARKETPLACE_SIGNING_KEY_PATH in the .env file to the new place, and keep one more copy offline. Packages are signed as before.',
                items: [$item[0] + ['status' => 'warning']],
            );
        }

        return $check->passed('', [], $item);
    }

    private function trusted(PendingCheck $check): CheckResult
    {
        if (! $this->key->exists()) {
            return $check->skipped('There is no signing key on this server yet.');
        }

        $known = array_filter([(string) config('nuvabill.marketplace.public_key'), ...array_map('strval', (array) config('nuvabill.marketplace.public_keys'))]);

        try {
            $public = $this->key->publicKey();
        } catch (RuntimeException) {
            return $check->urgent('The key file cannot be read as a signing key', [],
                advice: 'The store cannot sign packages with this file. Put back your copy of the key file.',
            );
        }

        if (in_array($public, $known, true)) {
            return $check->passed();
        }

        return $check->urgent('The key on this server is not a key Nuvabill sites trust', [],
            advice: 'Sites only install packages signed with a key that is in config/nuvabill.php (marketplace.public_key or marketplace.public_keys). Packages signed with the key on this server are refused. Put the right key file back, or ship this key\'s public half in a Nuvabill release first.',
        );
    }

    private function isInside(string $path, string $folder): bool
    {
        $folder = rtrim($this->normal((string) (realpath($folder) ?: $folder)), '/');

        return $folder !== '' && str_starts_with($path, $folder.'/');
    }

    private function normal(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
