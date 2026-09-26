<?php

namespace Nuvabill\Extensions\ResellerClub;

use App\Extensions\Registrars\Contact;
use App\Extensions\Registrars\Registrar;
use App\Extensions\Registrars\RegistrarResult;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ResellerClub (LogicBoxes) HTTP API. Every domain is registered under a ResellerClub customer
 * account with the client's email, and one contact is used for all four contact roles.
 */
class ResellerClubRegistrar extends Registrar
{
    public function settingsFields(): array
    {
        return [
            'reseller_id' => [
                'label' => 'Reseller ID',
                'type' => 'text',
                'required' => true,
                'help' => 'Shown in your ResellerClub panel under Settings → Personal information.',
            ],
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'required' => true,
                'help' => 'Settings → API. Also add this server\'s IP address to the API allow list there.',
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'options' => ['live' => 'Live', 'test' => 'Test (demo account)'],
                'help' => 'Test mode uses test.httpapi.com with a ResellerClub demo account.',
            ],
        ];
    }

    public function testConnection(): RegistrarResult
    {
        try {
            $this->call('GET', 'domains/available.json', ['domain-name' => ['nuvabill-connection-test-'.Str::lower(Str::random(6))], 'tlds' => ['com']]);
        } catch (RuntimeException $exception) {
            return RegistrarResult::fail($exception->getMessage());
        }

        return RegistrarResult::ok(__('Connected to ResellerClub.'));
    }

    public function checkAvailability(array $domains): array
    {
        $results = array_fill_keys($domains, null);

        foreach ($this->byExtension($domains) as $tld => $labels) {
            try {
                $response = $this->call('GET', 'domains/available.json', ['domain-name' => $labels, 'tlds' => [$tld]]);
            } catch (RuntimeException) {
                continue;
            }

            foreach ($labels as $label) {
                $status = strtolower((string) ($response[$label.'.'.$tld]['status'] ?? ''));
                $results[$label.'.'.$tld] = match ($status) {
                    'available' => true,
                    'regthroughus', 'regthroughothers' => false,
                    default => null,
                };
            }
        }

        return $results;
    }

    public function register(Domain $domain, Contact $contact): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $contact): RegistrarResult {
            [$customerId, $contactId] = $this->customerAndContact($contact);

            $response = $this->call('POST', 'domains/register.json', [
                'domain-name' => $domain->name,
                'years' => $domain->years,
                'ns' => $this->nameserversFor($domain, $customerId),
                'customer-id' => $customerId,
                'reg-contact-id' => $contactId,
                'admin-contact-id' => $contactId,
                'tech-contact-id' => $contactId,
                'billing-contact-id' => $contactId,
                'invoice-option' => 'NoInvoice',
                'protect-privacy' => 'false',
            ]);

            return RegistrarResult::ok(__('Registered with ResellerClub.'), [
                'registrar_data' => ['order_id' => (string) ($response['entityid'] ?? ''), 'customer_id' => $customerId],
            ]);
        });
    }

    public function transfer(Domain $domain, Contact $contact, string $eppCode): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $contact, $eppCode): RegistrarResult {
            [$customerId, $contactId] = $this->customerAndContact($contact);

            $response = $this->call('POST', 'domains/transfer.json', [
                'domain-name' => $domain->name,
                'auth-code' => $eppCode,
                'customer-id' => $customerId,
                'reg-contact-id' => $contactId,
                'admin-contact-id' => $contactId,
                'tech-contact-id' => $contactId,
                'billing-contact-id' => $contactId,
                'invoice-option' => 'NoInvoice',
                'protect-privacy' => 'false',
            ]);

            return RegistrarResult::ok(__('Transfer started at ResellerClub.'), [
                'registrar_data' => ['order_id' => (string) ($response['entityid'] ?? ''), 'customer_id' => $customerId],
            ]);
        });
    }

    public function renew(Domain $domain, int $years): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $years): RegistrarResult {
            $details = $this->details($domain);

            $this->call('POST', 'domains/renew.json', [
                'order-id' => $details['orderid'] ?? $details['entityid'] ?? '',
                'years' => $years,
                'exp-date' => (int) ($details['endtime'] ?? 0),
                'invoice-option' => 'NoInvoice',
            ]);

            $endTime = (int) ($details['endtime'] ?? 0);

            return RegistrarResult::ok(__('Renewed with ResellerClub.'), array_filter([
                'expires_at' => $endTime > 0 ? CarbonImmutable::createFromTimestamp($endTime)->addYearsNoOverflow($years)->toDateString() : null,
            ]));
        });
    }

    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $nameservers): RegistrarResult {
            $details = $this->details($domain);

            $this->call('POST', 'domains/modify-ns.json', [
                'order-id' => $details['orderid'] ?? $details['entityid'] ?? '',
                'ns' => $nameservers,
            ]);

            return RegistrarResult::ok(__('Nameservers updated at ResellerClub.'));
        });
    }

    public function sync(Domain $domain): RegistrarResult
    {
        return $this->attempt(function () use ($domain): RegistrarResult {
            $details = $this->details($domain);
            $endTime = (int) ($details['endtime'] ?? 0);
            $expiresAt = $endTime > 0 ? CarbonImmutable::createFromTimestamp($endTime) : null;

            $nameservers = [];

            for ($i = 1; $i <= 13; $i++) {
                if (filled($details['ns'.$i] ?? null)) {
                    $nameservers[] = strtolower((string) $details['ns'.$i]);
                }
            }

            $status = match (true) {
                ($details['currentstatus'] ?? null) !== 'Active' => null,
                $expiresAt !== null && $expiresAt->isPast() => 'expired',
                default => 'active',
            };

            return RegistrarResult::ok(__('Read from ResellerClub.'), array_filter([
                'expires_at' => $expiresAt?->toDateString(),
                'status' => $status,
                'nameservers' => $nameservers ?: null,
            ]));
        });
    }

    /**
     * Find the ResellerClub customer for the client's email, or create one, then add a contact.
     *
     * @return array{0: string, 1: string}
     */
    private function customerAndContact(Contact $contact): array
    {
        try {
            $customer = $this->call('GET', 'customers/details.json', ['username' => $contact->email]);
            $customerId = (string) ($customer['customerid'] ?? '');
        } catch (RuntimeException) {
            $customerId = '';
        }

        $address = [
            'name' => $contact->fullName(),
            'company' => $contact->company ?: 'N/A',
            'address-line-1' => $contact->address1,
            'city' => $contact->city,
            'state' => $contact->state,
            'country' => $contact->country,
            'zipcode' => $contact->postcode,
            'phone-cc' => $contact->phoneCountryCode,
            'phone' => $contact->phoneNumber,
        ];

        if ($customerId === '') {
            $customerId = (string) $this->call('POST', 'customers/v2/signup.json', $address + [
                'username' => $contact->email,
                'passwd' => Str::password(16, symbols: false).'a1',
                'lang-pref' => 'en',
            ]);
        }

        $contactId = (string) $this->call('POST', 'contacts/add.json', $address + [
            'email' => $contact->email,
            'customer-id' => $customerId,
            'type' => 'Contact',
        ]);

        return [$customerId, $contactId];
    }

    /**
     * @return list<string>
     */
    private function nameserversFor(Domain $domain, string $customerId): array
    {
        $nameservers = $domain->nameserverList();

        if (count($nameservers) >= 2) {
            return $nameservers;
        }

        return array_values((array) $this->call('GET', 'domains/customer-default-ns.json', ['customer-id' => $customerId]));
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Domain $domain): array
    {
        return (array) $this->call('GET', 'domains/details-by-name.json', ['domain-name' => $domain->name, 'options' => 'All']);
    }

    /**
     * Group names by extension, so one request checks many names: ['com' => ['shop', 'bakery']].
     *
     * @param  list<string>  $domains
     * @return array<string, list<string>>
     */
    private function byExtension(array $domains): array
    {
        $groups = [];

        foreach ($domains as $domain) {
            [$label, $tld] = explode('.', $domain, 2) + [1 => ''];
            $groups[$tld][] = $label;
        }

        return $groups;
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
     * Call the API. Parameters go in the query string; lists become repeated keys (ns=a&ns=b).
     *
     * @param  array<string, mixed>  $params
     */
    private function call(string $method, string $path, array $params = []): mixed
    {
        $base = $this->inTestMode() ? 'https://test.httpapi.com/api/' : 'https://httpapi.com/api/';
        $query = http_build_query(['auth-userid' => $this->setting('reseller_id'), 'api-key' => $this->setting('api_key')] + $params);
        $url = $base.$path.'?'.preg_replace('/%5B\d+%5D=/', '=', $query);

        try {
            $response = Http::timeout(60)->acceptJson()->send($method, $url);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('Could not connect to ResellerClub: :error', ['error' => $exception->getMessage()]));
        }

        $data = $response->json();

        if (is_array($data) && in_array(strtolower((string) ($data['status'] ?? '')), ['error', 'failed'], true)) {
            throw new RuntimeException('ResellerClub: '.($data['message'] ?? $data['error'] ?? __('unknown error')));
        }

        if (is_array($data) && ($data['actionstatus'] ?? null) === 'Failed') {
            throw new RuntimeException('ResellerClub: '.($data['actionstatusdesc'] ?? __('the action failed')));
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('ResellerClub returned HTTP :status.', ['status' => $response->status()]));
        }

        return $data ?? trim($response->body());
    }
}
