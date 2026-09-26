<?php

namespace Nuvabill\Extensions\OpenSrs;

use App\Extensions\Registrars\Contact;
use App\Extensions\Registrars\Registrar;
use App\Extensions\Registrars\RegistrarResult;
use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * OpenSRS XML API (OPS envelope). Requests are signed with md5(md5(xml + key) + key).
 */
class OpenSrsRegistrar extends Registrar
{
    public function settingsFields(): array
    {
        return [
            'username' => [
                'label' => 'Reseller username',
                'type' => 'text',
                'required' => true,
            ],
            'api_key' => [
                'label' => 'API key',
                'type' => 'password',
                'required' => true,
                'help' => 'Generate it in the Reseller Control Panel under Profile → API settings, and allow this server\'s IP address.',
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'options' => ['live' => 'Live', 'test' => 'Test (horizon.opensrs.net)'],
            ],
        ];
    }

    public function testConnection(): RegistrarResult
    {
        return $this->attempt(function (): RegistrarResult {
            $response = $this->call('GET_BALANCE', 'BALANCE');

            return RegistrarResult::ok(__('Connected to OpenSRS. Balance: :amount.', ['amount' => (string) ($response['attributes']['balance'] ?? '')]));
        });
    }

    public function checkAvailability(array $domains): array
    {
        $results = array_fill_keys($domains, null);

        foreach ($domains as $domain) {
            try {
                $response = $this->call('LOOKUP', 'DOMAIN', ['domain' => $domain], allowCodes: [210, 211]);
            } catch (RuntimeException) {
                continue;
            }

            $results[$domain] = match ((int) ($response['response_code'] ?? 0)) {
                210 => true,
                211 => false,
                default => null,
            };
        }

        return $results;
    }

    public function register(Domain $domain, Contact $contact): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $contact): RegistrarResult {
            $nameservers = $domain->nameserverList();

            $response = $this->call('SW_REGISTER', 'DOMAIN', [
                'domain' => $domain->name,
                'reg_type' => 'new',
                'period' => $domain->years,
                'reg_username' => Str::lower(Str::random(12)),
                'reg_password' => Str::password(16, symbols: false),
                'handle' => 'process',
                'custom_tech_contact' => 0,
                'custom_nameservers' => $nameservers === [] ? 0 : 1,
                'nameserver_list' => $this->nameserverList($nameservers),
                'contact_set' => $this->contactSet($contact),
            ]);

            return RegistrarResult::ok(__('Registered with OpenSRS.'), [
                'registrar_data' => ['order_id' => (string) ($response['attributes']['id'] ?? '')],
            ]);
        });
    }

    public function transfer(Domain $domain, Contact $contact, string $eppCode): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $contact, $eppCode): RegistrarResult {
            $response = $this->call('SW_REGISTER', 'DOMAIN', [
                'domain' => $domain->name,
                'reg_type' => 'transfer',
                'period' => 1,
                'auth_info' => $eppCode,
                'reg_username' => Str::lower(Str::random(12)),
                'reg_password' => Str::password(16, symbols: false),
                'handle' => 'process',
                'custom_tech_contact' => 0,
                'custom_nameservers' => 0,
                'contact_set' => $this->contactSet($contact),
            ]);

            return RegistrarResult::ok(__('Transfer started at OpenSRS.'), [
                'registrar_data' => ['order_id' => (string) ($response['attributes']['id'] ?? '')],
            ]);
        });
    }

    public function renew(Domain $domain, int $years): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $years): RegistrarResult {
            $expiresAt = $this->expiryOf($domain);

            if ($expiresAt === null) {
                return RegistrarResult::fail(__('OpenSRS did not return the expiry date of :domain.', ['domain' => $domain->name]));
            }

            $this->call('RENEW', 'DOMAIN', [
                'domain' => $domain->name,
                'period' => $years,
                'currentexpirationyear' => $expiresAt->year,
                'handle' => 'process',
                'auto_renew' => 0,
            ]);

            return RegistrarResult::ok(__('Renewed with OpenSRS.'), ['expires_at' => $expiresAt->addYearsNoOverflow($years)->toDateString()]);
        });
    }

    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        return $this->attempt(function () use ($domain, $nameservers): RegistrarResult {
            $this->call('ADVANCED_UPDATE_NAMESERVERS', 'NAMESERVER', [
                'domain' => $domain->name,
                'op_type' => 'assign',
                'assign_ns' => array_values($nameservers),
            ]);

            return RegistrarResult::ok(__('Nameservers updated at OpenSRS.'));
        });
    }

    public function sync(Domain $domain): RegistrarResult
    {
        return $this->attempt(function () use ($domain): RegistrarResult {
            $info = $this->call('GET', 'DOMAIN', ['domain' => $domain->name, 'type' => 'all_info']);
            $attributes = (array) ($info['attributes'] ?? []);
            $expiresAt = $this->date((string) ($attributes['expiredate'] ?? ''));

            $nameservers = collect((array) ($attributes['nameserver_list'] ?? []))
                ->map(fn (mixed $nameserver): string => strtolower((string) (is_array($nameserver) ? ($nameserver['name'] ?? '') : $nameserver)))
                ->filter()
                ->values()
                ->all();

            return RegistrarResult::ok(__('Read from OpenSRS.'), array_filter([
                'expires_at' => $expiresAt?->toDateString(),
                'status' => $expiresAt === null ? null : ($expiresAt->isPast() ? 'expired' : 'active'),
                'nameservers' => $nameservers ?: null,
            ]));
        });
    }

    private function expiryOf(Domain $domain): ?CarbonImmutable
    {
        $info = $this->call('GET', 'DOMAIN', ['domain' => $domain->name, 'type' => 'all_info']);

        return $this->date((string) ($info['attributes']['expiredate'] ?? ''));
    }

    /**
     * @param  list<string>  $nameservers
     * @return list<array{name: string, sortorder: int}>
     */
    private function nameserverList(array $nameservers): array
    {
        return array_map(fn (string $name, int $index): array => ['name' => $name, 'sortorder' => $index + 1], $nameservers, array_keys($nameservers));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function contactSet(Contact $contact): array
    {
        $details = array_filter([
            'first_name' => $contact->firstName,
            'last_name' => $contact->lastName,
            'org_name' => $contact->company ?: $contact->fullName(),
            'address1' => $contact->address1,
            'address2' => $contact->address2,
            'city' => $contact->city,
            'state' => $contact->state,
            'postal_code' => $contact->postcode,
            'country' => $contact->country,
            'phone' => $contact->dottedPhone(),
            'email' => $contact->email,
        ], fn (string $value): bool => $value !== '');

        return ['owner' => $details, 'admin' => $details, 'billing' => $details, 'tech' => $details];
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
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
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $allowCodes  Response codes that are answers, not errors (for example 211 "taken").
     * @return array<string, mixed>
     */
    private function call(string $action, string $object, array $attributes = [], array $allowCodes = []): array
    {
        $xml = $this->envelope(['protocol' => 'XCP', 'action' => $action, 'object' => $object, 'attributes' => $attributes]);
        $key = (string) $this->setting('api_key');
        $url = $this->inTestMode() ? 'https://horizon.opensrs.net:55443' : 'https://rr-n1-tor.opensrs.net:55443';

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'X-Username' => (string) $this->setting('username'),
                    'X-Signature' => md5(md5($xml.$key).$key),
                ])
                ->withBody($xml, 'text/xml')
                ->post($url);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('Could not connect to OpenSRS: :error', ['error' => $exception->getMessage()]));
        }

        $data = $this->parse($response->body());

        if ($data === null) {
            throw new RuntimeException(__('OpenSRS returned HTTP :status.', ['status' => $response->status()]));
        }

        $code = (int) ($data['response_code'] ?? 0);

        if ((int) ($data['is_success'] ?? 0) !== 1 && ! in_array($code, [200, ...$allowCodes], true)) {
            throw new RuntimeException('OpenSRS: '.($data['response_text'] ?? __('unknown error')));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function envelope(array $data): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="no" ?>'."\n"
            .'<!DOCTYPE OPS_envelope SYSTEM "ops.dtd">'."\n"
            .'<OPS_envelope><header><version>0.9</version></header><body><data_block>'
            .$this->encode($data)
            .'</data_block></body></OPS_envelope>';
    }

    private function encode(mixed $value): string
    {
        if (! is_array($value)) {
            return htmlspecialchars((string) $value, ENT_XML1);
        }

        $tag = array_is_list($value) ? 'dt_array' : 'dt_assoc';
        $items = '';

        foreach ($value as $key => $item) {
            $items .= '<item key="'.htmlspecialchars((string) $key, ENT_XML1).'">'.$this->encode($item).'</item>';
        }

        return "<{$tag}>{$items}</{$tag}>";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(string $body): ?array
    {
        $xml = @simplexml_load_string($body);

        if ($xml === false || ! isset($xml->body->data_block)) {
            return null;
        }

        $value = $this->decode($xml->body->data_block->children()[0]);

        return is_array($value) ? $value : null;
    }

    private function decode(SimpleXMLElement $node): mixed
    {
        if (! in_array($node->getName(), ['dt_assoc', 'dt_array'], true)) {
            return (string) $node;
        }

        $result = [];

        foreach ($node->item as $item) {
            $children = $item->children();
            $result[(string) $item['key']] = count($children) > 0 ? $this->decode($children[0]) : (string) $item;
        }

        return $result;
    }
}
