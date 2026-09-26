<?php

namespace Nuvabill\Extensions\Enom;

use App\Extensions\Registrars\Contact;
use App\Extensions\Registrars\Registrar;
use App\Extensions\Registrars\RegistrarResult;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Enom reseller API (interface.asp) with XML responses.
 */
class EnomRegistrar extends Registrar
{
    public function settingsFields(): array
    {
        return [
            'uid' => [
                'label' => 'Login ID',
                'type' => 'text',
                'required' => true,
            ],
            'api_token' => [
                'label' => 'API token',
                'type' => 'password',
                'required' => true,
                'help' => 'Create one in your Enom account under Resellers → API Tokens, and allow this server\'s IP address.',
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'options' => ['live' => 'Live', 'test' => 'Test (resellertest.enom.com)'],
            ],
        ];
    }

    public function testConnection(): RegistrarResult
    {
        return $this->attempt(function (): RegistrarResult {
            $response = $this->call('GetBalance');

            return RegistrarResult::ok(__('Connected to Enom. Available balance: :amount.', ['amount' => (string) $response->AvailableBalance]));
        });
    }

    public function checkAvailability(array $domains): array
    {
        $results = array_fill_keys($domains, null);

        foreach (array_chunk($domains, 30) as $chunk) {
            try {
                $response = $this->call('Check', ['DomainList' => implode(',', $chunk)]);
            } catch (RuntimeException) {
                continue;
            }

            for ($i = 1; $i <= count($chunk); $i++) {
                $name = strtolower((string) ($response->{'Domain'.$i} ?? ''));
                $code = (int) ($response->{'RRPCode'.$i} ?? 0);

                if ($name !== '') {
                    $results[$name] = match ($code) {
                        210 => true,
                        211 => false,
                        default => null,
                    };
                }
            }
        }

        return $results;
    }

    public function register(Domain $domain, Contact $contact): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $contact): RegistrarResult {
            $response = $this->call('Purchase', [
                'SLD' => $domain->sld(),
                'TLD' => $domain->tld,
                'NumYears' => $domain->years,
            ] + $this->nameserverParams($domain->nameserverList()) + $this->contactParams($contact));

            return RegistrarResult::ok(__('Registered with Enom.'), [
                'registrar_data' => ['order_id' => (string) $response->OrderID],
            ]);
        });
    }

    public function transfer(Domain $domain, Contact $contact, string $eppCode): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $eppCode): RegistrarResult {
            $response = $this->call('TP_CreateOrder', [
                'DomainCount' => 1,
                'SLD1' => $domain->sld(),
                'TLD1' => $domain->tld,
                'AuthInfo1' => $eppCode,
                'OrderType' => 'Autoverification',
                'UseContacts' => 1,
            ]);

            return RegistrarResult::ok(__('Transfer started at Enom.'), [
                'registrar_data' => ['transfer_order_id' => (string) $response->transferorder->transferorderid],
            ]);
        });
    }

    public function renew(Domain $domain, int $years): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $years): RegistrarResult {
            $this->call('Extend', ['SLD' => $domain->sld(), 'TLD' => $domain->tld, 'NumYears' => $years]);

            return RegistrarResult::ok(__('Renewed with Enom.'));
        });
    }

    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $nameservers): RegistrarResult {
            $this->call('ModifyNS', ['SLD' => $domain->sld(), 'TLD' => $domain->tld] + $this->nameserverParams($nameservers));

            return RegistrarResult::ok(__('Nameservers updated at Enom.'));
        });
    }

    public function sync(Domain $domain): RegistrarResult
    {
        return $this->attempt(function () use ($domain): RegistrarResult {
            $info = $this->call('GetDomainInfo', ['SLD' => $domain->sld(), 'TLD' => $domain->tld])->GetDomainInfo;
            $expiresAt = $this->date((string) $info->status->expiration);
            $registration = strtolower((string) $info->status->registrationstatus);

            $status = match (true) {
                str_contains($registration, 'expired') => 'expired',
                str_contains($registration, 'registered') => $expiresAt !== null && CarbonImmutable::parse($expiresAt)->isPast() ? 'expired' : 'active',
                default => null,
            };

            return RegistrarResult::ok(__('Read from Enom.'), array_filter(['expires_at' => $expiresAt, 'status' => $status]));
        });
    }

    /**
     * Nameservers as NS1, NS2, ...; without any, Enom's own DNS is used.
     *
     * @param  list<string>  $nameservers
     * @return array<string, string>
     */
    private function nameserverParams(array $nameservers): array
    {
        if ($nameservers === []) {
            return ['UseDNS' => 'default'];
        }

        $params = [];

        foreach (array_values($nameservers) as $index => $nameserver) {
            $params['NS'.($index + 1)] = $nameserver;
        }

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function contactParams(Contact $contact): array
    {
        $params = [];

        foreach (['Registrant', 'Admin', 'Tech', 'AuxBilling'] as $role) {
            $params += [
                $role.'FirstName' => $contact->firstName,
                $role.'LastName' => $contact->lastName,
                $role.'OrganizationName' => $contact->company,
                $role.'Address1' => $contact->address1,
                $role.'Address2' => $contact->address2,
                $role.'City' => $contact->city,
                $role.'StateProvince' => $contact->state,
                $role.'PostalCode' => $contact->postcode,
                $role.'Country' => $contact->country,
                $role.'EmailAddress' => $contact->email,
                $role.'Phone' => $contact->dottedPhone(),
            ];
        }

        return array_filter($params, fn (string $value): bool => $value !== '');
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  callable(): RegistrarResult  $callback
     */
    private function attempt(callable $callback): RegistrarResult
    {
        try {
            return $callback();
        } catch (RuntimeException $exception) {
            return RegistrarResult::fail($exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function call(string $command, array $params = []): SimpleXMLElement
    {
        $url = $this->inTestMode() ? 'https://resellertest.enom.com/interface.asp' : 'https://reseller.enom.com/interface.asp';

        try {
            $response = Http::timeout(60)->get($url, [
                'uid' => $this->setting('uid'),
                'pw' => $this->setting('api_token'),
                'command' => $command,
                'responsetype' => 'xml',
            ] + $params);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('Could not connect to Enom: :error', ['error' => $exception->getMessage()]));
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            throw new RuntimeException(__('Enom returned HTTP :status.', ['status' => $response->status()]));
        }

        if ((int) ($xml->ErrCount ?? 0) > 0) {
            $errors = [];

            foreach ($xml->errors->children() as $error) {
                $errors[] = trim((string) $error);
            }

            throw new RuntimeException('Enom: '.($errors !== [] ? implode(' ', $errors) : __('unknown error')));
        }

        return $xml;
    }
}
