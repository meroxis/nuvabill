<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Reads and writes system settings stored in the database. Values are cached and JSON encoded,
 * so booleans, numbers and arrays keep their type. Secret keys are encrypted at rest.
 */
class Settings
{
    private const CACHE_KEY = 'nuvabill.settings';

    /**
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'company.name' => 'My Hosting Company',
        'company.email' => 'billing@example.com',
        'company.address' => '',
        'company.phone' => '',

        'billing.currency' => 'USD',
        'billing.invoice_prefix' => 'INV-',
        'billing.renewal_days_before' => 7,
        'billing.payment_terms_days' => 7,

        'automation.enabled' => true,
        'automation.reminder_days' => [1, 3, 7],
        'automation.suspend_days' => 3,
        'automation.terminate_days' => 0,
        'automation.last_run_at' => null,

        'branding.accent' => '#0B7A70',

        'theme.active' => 'nova',

        'orders.accept_terms_url' => '',

        'mail.mailer' => 'log',
        'mail.host' => '',
        'mail.port' => 587,
        'mail.username' => '',
        'mail.password' => '',
        'mail.encryption' => 'tls',
        'mail.from_address' => 'billing@example.com',
        'mail.from_name' => 'My Hosting Company',

        'updates.channel' => 'stable',
        'updates.auto_security' => true,
        'updates.auto_all' => false,
        'updates.last_checked_at' => null,
        'updates.latest' => null,
    ];

    /**
     * Keys stored encrypted in the database.
     *
     * @var list<string>
     */
    public const SECRET_KEYS = ['mail.password'];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->all();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $stored = json_encode($value, JSON_THROW_ON_ERROR);

            if (in_array($key, self::SECRET_KEYS, true) && $value !== null && $value !== '') {
                $stored = Crypt::encryptString($stored);
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored]);
        }

        $this->flush();
    }

    /**
     * All settings, with defaults filled in for keys never saved.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        if (! Installation::isInstalled()) {
            return $this->values = self::DEFAULTS;
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());
        } catch (QueryException) {
            // The database is not migrated yet (fresh copy or first deploy): use the defaults for now.
            return self::DEFAULTS;
        }

        $decoded = [];

        foreach ($stored as $key => $raw) {
            $decoded[$key] = $this->decode($key, $raw);
        }

        return $this->values = array_merge(self::DEFAULTS, $decoded);
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }

    private function decode(string $key, ?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        if (in_array($key, self::SECRET_KEYS, true) && ! str_starts_with($raw, '"') && $raw !== 'null') {
            try {
                $raw = Crypt::decryptString($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        return json_decode($raw, true);
    }
}
