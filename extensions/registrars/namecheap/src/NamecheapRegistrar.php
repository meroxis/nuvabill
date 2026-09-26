<?php

namespace Nuvabill\Extensions\Namecheap;

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
 * Namecheap XML API. Namecheap only answers calls from IP addresses allowed in the account,
 * and every call must say which allowed IP it comes from ("ClientIp").
 */
class NamecheapRegistrar extends Registrar
{
    public function settingsFields(): array
    {
        return [
            'api_user' => [
                'label' => 'API user',
                'type' => 'text',
                'required' => true,
                'help' => 'Your Namecheap username.',
            ],
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'required' => true,
                'help' => 'Profile → Tools → Namecheap API Access. Turn the API on first.',
            ],
            'client_ip' => [
                'label' => 'This server\'s public IP address',
                'type' => 'text',
                'required' => true,
                'help' => 'Add the same IP to the allowed list on the Namecheap API Access page.',
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'options' => ['live' => 'Live', 'sandbox' => 'Sandbox'],
                'help' => 'The sandbox (sandbox.namecheap.com) has its own account and API key.',
            ],
        ];
    }

    public function testConnection(): RegistrarResult
    {
        return $this->attempt(function (): RegistrarResult {
            $balance = $this->call('namecheap.users.getBalances')->UserGetBalancesResult;

            return RegistrarResult::ok(__('Connected to Namecheap. Balance: :amount :currency.', [
                'amount' => (string) $balance['AvailableBalance'],
                'currency' => (string) $balance['Currency'],
            ]));
        });
    }

    public function checkAvailability(array $domains): array
    {
        $results = array_fill_keys($domains, null);

        try {
            $response = $this->call('namecheap.domains.check', ['DomainList' => implode(',', $domains)]);
        } catch (RuntimeException) {
            return $results;
        }

        foreach ($response->DomainCheckResult as $result) {
            $name = strtolower((string) $result['Domain']);

            // Premium names cost much more than the normal price, so they are not sold at the normal price.
            $results[$name] = (string) $result['Available'] === 'true' && (string) $result['IsPremiumName'] !== 'true';
        }

        return $results;
    }

    public function register(Domain $domain, Contact $contact): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $contact): RegistrarResult {
            $result = $this->call('namecheap.domains.create', [
                'DomainName' => $domain->name,
                'Years' => $domain->years,
                'Nameservers' => implode(',', $domain->nameserverList()),
                'AddFreeWhoisguard' => 'yes',
                'WGEnabled' => 'yes',
            ] + $this->contactParams($contact))->DomainCreateResult;

            if ((string) $result['Registered'] !== 'true') {
                return RegistrarResult::fail(__('Namecheap did not register :domain.', ['domain' => $domain->name]));
            }

            return RegistrarResult::ok(__('Registered with Namecheap.'), [
                'registrar_data' => ['order_id' => (string) $result['OrderID'], 'domain_id' => (string) $result['DomainID']],
            ]);
        });
    }

    public function transfer(Domain $domain, Contact $contact, string $eppCode): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $eppCode): RegistrarResult {
            $result = $this->call('namecheap.domains.transfer.create', [
                'DomainName' => $domain->name,
                'Years' => 1,
                'EPPCode' => 'base64:'.base64_encode($eppCode),
                'AddFreeWhoisguard' => 'yes',
                'WGEnabled' => 'yes',
            ])->DomainTransferCreateResult;

            return RegistrarResult::ok(__('Transfer started at Namecheap.'), [
                'registrar_data' => ['transfer_id' => (string) $result['TransferID'], 'order_id' => (string) $result['OrderID']],
            ]);
        });
    }

    public function renew(Domain $domain, int $years): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $years): RegistrarResult {
            $result = $this->call('namecheap.domains.renew', ['DomainName' => $domain->name, 'Years' => $years])->DomainRenewResult;

            return RegistrarResult::ok(__('Renewed with Namecheap.'), array_filter([
                'expires_at' => $this->date((string) ($result->DomainDetails->ExpiredDate ?? '')),
            ]));
        });
    }

    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $nameservers): RegistrarResult {
            $this->call('namecheap.domains.dns.setCustom', [
                'SLD' => $domain->sld(),
                'TLD' => $domain->tld,
                'NameServers' => implode(',', $nameservers),
            ]);

            return RegistrarResult::ok(__('Nameservers updated at Namecheap.'));
        });
    }

    public function sync(Domain $domain): RegistrarResult
    {
        return $this->attempt(function () use ($domain): RegistrarResult {
            $result = $this->call('namecheap.domains.getinfo', ['DomainName' => $domain->name])->DomainGetInfoResult;
            $expiresAt = $this->date((string) $result->DomainDetails->ExpiredDate);

            $nameservers = [];

            foreach ($result->DnsDetails->Nameserver ?? [] as $nameserver) {
                $nameservers[] = strtolower((string) $nameserver);
            }

            $status = match (true) {
                strtolower((string) $result['Status']) === 'expired' => 'expired',
                strtolower((string) $result['Status']) === 'ok' => $expiresAt !== null && CarbonImmutable::parse($expiresAt)->isPast() ? 'expired' : 'active',
                default => null,
            };

            return RegistrarResult::ok(__('Read from Namecheap.'), array_filter([
                'expires_at' => $expiresAt,
                'status' => $status,
                'nameservers' => $nameservers ?: null,
            ]));
        });
    }

    /**
     * The same contact for the four roles Namecheap asks for.
     *
     * @return array<string, string>
     */
    private function contactParams(Contact $contact): array
    {
        $params = [];

        foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
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
                $role.'Phone' => $contact->dottedPhone(),
                $role.'EmailAddress' => $contact->email,
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
            return CarbonImmutable::createFromFormat('m/d/Y', $value)?->toDateString();
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
        $url = $this->inTestMode() ? 'https://api.sandbox.namecheap.com/xml.response' : 'https://api.namecheap.com/xml.response';
        $user = (string) $this->setting('api_user');

        try {
            $response = Http::timeout(60)->get($url, [
                'ApiUser' => $user,
                'ApiKey' => $this->setting('api_key'),
                'UserName' => $user,
                'ClientIp' => $this->setting('client_ip'),
                'Command' => $command,
            ] + $params);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('Could not connect to Namecheap: :error', ['error' => $exception->getMessage()]));
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            throw new RuntimeException(__('Namecheap returned HTTP :status.', ['status' => $response->status()]));
        }

        if ((string) $xml['Status'] !== 'OK') {
            $errors = [];

            foreach ($xml->Errors->Error ?? [] as $error) {
                $errors[] = trim((string) $error);
            }

            throw new RuntimeException('Namecheap: '.($errors !== [] ? implode(' ', $errors) : __('unknown error')));
        }

        return $xml->CommandResponse;
    }
}
