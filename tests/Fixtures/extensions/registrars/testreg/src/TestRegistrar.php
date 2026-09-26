<?php

namespace Tests\Fixtures\Registrars;

use App\Extensions\Registrars\Contact;
use App\Extensions\Registrars\Registrar;
use App\Extensions\Registrars\RegistrarResult;
use App\Models\Domain;

/**
 * Records every call so tests can see what Nuvabill asked the registrar to do.
 */
class TestRegistrar extends Registrar
{
    /**
     * @var list<array{0: string, 1: string, 2?: mixed}>
     */
    public static array $calls = [];

    /**
     * @var list<string>
     */
    public static array $taken = [];

    public static bool $fail = false;

    public static function reset(): void
    {
        self::$calls = [];
        self::$taken = [];
        self::$fail = false;
    }

    public function settingsFields(): array
    {
        return ['api_key' => ['label' => 'API key', 'type' => 'password', 'required' => true]];
    }

    public function testConnection(): RegistrarResult
    {
        return RegistrarResult::ok('Connected');
    }

    public function checkAvailability(array $domains): array
    {
        return array_combine($domains, array_map(fn (string $domain): bool => ! in_array($domain, self::$taken, true), $domains));
    }

    public function register(Domain $domain, Contact $contact): RegistrarResult
    {
        self::$calls[] = ['register', $domain->name, $contact->dottedPhone()];

        return self::$fail
            ? RegistrarResult::fail('The registrar said no.')
            : RegistrarResult::ok('Registered', ['expires_at' => today()->addYears($domain->years)->toDateString(), 'registrar_data' => ['order_id' => 'R-1']]);
    }

    public function transfer(Domain $domain, Contact $contact, string $eppCode): RegistrarResult
    {
        self::$calls[] = ['transfer', $domain->name, $eppCode];

        return RegistrarResult::ok('Transfer started');
    }

    public function renew(Domain $domain, int $years): RegistrarResult
    {
        self::$calls[] = ['renew', $domain->name, $years];

        return RegistrarResult::ok('Renewed');
    }

    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        self::$calls[] = ['nameservers', $domain->name, $nameservers];

        return RegistrarResult::ok('Saved');
    }

    public function sync(Domain $domain): RegistrarResult
    {
        self::$calls[] = ['sync', $domain->name];

        return RegistrarResult::ok('Synced', ['status' => 'active', 'expires_at' => ($domain->expires_at ?? today()->addYear())->toDateString()]);
    }
}
