<?php

namespace Tests\Feature\Registrars;

use App\Contracts\DomainRegistrar;
use App\Extensions\ExtensionManager;
use App\Extensions\Registrars\Contact;
use App\Models\Client;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrarModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_resellerclub_checks_registers_and_manages_domains(): void
    {
        Http::fake([
            'test.httpapi.com/api/domains/available.json*' => Http::response(['free.com' => ['status' => 'available'], 'taken.com' => ['status' => 'regthroughothers']]),
            'test.httpapi.com/api/customers/details.json*' => Http::response(['status' => 'ERROR', 'message' => 'Customer not found'], 500),
            'test.httpapi.com/api/customers/v2/signup.json*' => Http::response('1001'),
            'test.httpapi.com/api/contacts/add.json*' => Http::response('2002'),
            'test.httpapi.com/api/domains/register.json*' => Http::response(['actionstatus' => 'Success', 'entityid' => '3003', 'status' => 'Success']),
            'test.httpapi.com/api/domains/details-by-name.json*' => Http::response(['orderid' => '3003', 'currentstatus' => 'Active', 'endtime' => (string) now()->addYear()->getTimestamp(), 'ns1' => 'NS1.HOST.TEST', 'ns2' => 'ns2.host.test']),
            'test.httpapi.com/api/domains/modify-ns.json*' => Http::response(['actionstatus' => 'Success']),
        ]);

        $registrar = $this->registrar('resellerclub', ['reseller_id' => '42', 'api_key' => 'key', 'mode' => 'test']);
        $domain = $this->domain('free.com');

        $this->assertSame(['free.com' => true, 'taken.com' => false], $registrar->checkAvailability(['free.com', 'taken.com']));

        $result = $registrar->register($domain, Contact::fromClient($domain->client));
        $this->assertTrue($result->success, $result->message);
        $this->assertSame('3003', $result->data['registrar_data']['order_id']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'domains/register.json')
            && str_contains($request->url(), 'ns=ns1.host.test&ns=ns2.host.test')
            && str_contains($request->url(), 'reg-contact-id=2002')
            && str_contains($request->url(), 'customer-id=1001')
            && str_contains($request->url(), 'auth-userid=42'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'contacts/add.json') && str_contains($request->url(), 'phone-cc=964') && str_contains($request->url(), 'phone=7501234567'));

        $this->assertTrue($registrar->setNameservers($domain, ['a.test', 'b.test'])->success);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'modify-ns.json') && str_contains($request->url(), 'order-id=3003&ns=a.test&ns=b.test'));

        $sync = $registrar->sync($domain);
        $this->assertSame('active', $sync->data['status']);
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $sync->data['nameservers']);
    }

    public function test_resellerclub_errors_become_readable_messages(): void
    {
        Http::fake(['httpapi.com/api/*' => Http::response(['status' => 'ERROR', 'message' => 'Invalid api-key'], 500)]);

        $result = $this->registrar('resellerclub', ['reseller_id' => '42', 'api_key' => 'bad', 'mode' => 'live'])->testConnection();

        $this->assertFalse($result->success);
        $this->assertSame('ResellerClub: Invalid api-key', $result->message);
    }

    public function test_namecheap_checks_registers_and_reads_domains(): void
    {
        Http::fake(['api.sandbox.namecheap.com/xml.response*' => function (Request $request) {
            return match ($request['Command']) {
                'namecheap.domains.check' => Http::response($this->namecheapXml('<DomainCheckResult Domain="free.com" Available="true" IsPremiumName="false"/><DomainCheckResult Domain="shop.com" Available="true" IsPremiumName="true"/><DomainCheckResult Domain="taken.com" Available="false"/>')),
                'namecheap.domains.create' => Http::response($this->namecheapXml('<DomainCreateResult Domain="free.com" Registered="true" DomainID="77" OrderID="88"/>')),
                'namecheap.domains.getinfo' => Http::response($this->namecheapXml('<DomainGetInfoResult Status="Ok" DomainName="free.com"><DomainDetails><ExpiredDate>09/27/2031</ExpiredDate></DomainDetails><DnsDetails><Nameserver>NS1.HOST.TEST</Nameserver><Nameserver>ns2.host.test</Nameserver></DnsDetails></DomainGetInfoResult>')),
                default => Http::response($this->namecheapXml('', 'ERROR', '<Error Number="2011166">Command not faked</Error>')),
            };
        }]);

        $registrar = $this->registrar('namecheap', ['api_user' => 'rapidnet', 'api_key' => 'key', 'client_ip' => '185.117.98.91', 'mode' => 'sandbox']);
        $domain = $this->domain('free.com');

        $this->assertSame(['free.com' => true, 'shop.com' => false, 'taken.com' => false], $registrar->checkAvailability(['free.com', 'shop.com', 'taken.com']));

        $result = $registrar->register($domain, Contact::fromClient($domain->client));
        $this->assertTrue($result->success, $result->message);
        Http::assertSent(fn (Request $request): bool => $request['Command'] === 'namecheap.domains.create'
            && $request['RegistrantPhone'] === '+964.7501234567'
            && $request['AuxBillingEmailAddress'] === $domain->client->email
            && $request['Nameservers'] === 'ns1.host.test,ns2.host.test'
            && $request['ClientIp'] === '185.117.98.91');

        $sync = $registrar->sync($domain);
        $this->assertSame(['expires_at' => '2031-09-27', 'status' => 'active', 'nameservers' => ['ns1.host.test', 'ns2.host.test']], $sync->data);

        $failed = $registrar->renew($domain, 1);
        $this->assertFalse($failed->success);
        $this->assertSame('Namecheap: Command not faked', $failed->message);
    }

    public function test_enom_checks_and_registers_domains(): void
    {
        Http::fake(['resellertest.enom.com/interface.asp*' => function (Request $request) {
            return match ($request['command']) {
                'Check' => Http::response('<?xml version="1.0"?><interface-response><Domain1>free.com</Domain1><RRPCode1>210</RRPCode1><Domain2>taken.com</Domain2><RRPCode2>211</RRPCode2><ErrCount>0</ErrCount></interface-response>'),
                'Purchase' => Http::response('<?xml version="1.0"?><interface-response><OrderID>555</OrderID><RRPCode>200</RRPCode><ErrCount>0</ErrCount></interface-response>'),
                default => Http::response('<?xml version="1.0"?><interface-response><ErrCount>1</ErrCount><errors><Err1>Domain name not found</Err1></errors></interface-response>'),
            };
        }]);

        $registrar = $this->registrar('enom', ['uid' => 'rapidnet', 'api_token' => 'token', 'mode' => 'test']);
        $domain = $this->domain('free.com');

        $this->assertSame(['free.com' => true, 'taken.com' => false], $registrar->checkAvailability(['free.com', 'taken.com']));

        $result = $registrar->register($domain, Contact::fromClient($domain->client));
        $this->assertTrue($result->success, $result->message);
        $this->assertSame('555', $result->data['registrar_data']['order_id']);
        Http::assertSent(fn (Request $request): bool => $request['command'] === 'Purchase' && $request['SLD'] === 'free' && $request['TLD'] === 'com'
            && $request['NS1'] === 'ns1.host.test' && $request['RegistrantPhone'] === '+964.7501234567');

        $this->assertSame('Enom: Domain name not found', $registrar->sync($domain)->message);
    }

    public function test_opensrs_signs_requests_and_reads_answers(): void
    {
        $reply = fn (string $inner): string => '<?xml version="1.0"?><OPS_envelope><header><version>0.9</version></header><body><data_block><dt_assoc>'.$inner.'</dt_assoc></data_block></body></OPS_envelope>';

        Http::fake(['horizon.opensrs.net:55443*' => function (Request $request) use ($reply) {
            $body = $request->body();

            return match (true) {
                str_contains($body, '<item key="action">LOOKUP</item>') && str_contains($body, 'free.com') => Http::response($reply('<item key="is_success">1</item><item key="response_code">210</item>')),
                str_contains($body, '<item key="action">LOOKUP</item>') => Http::response($reply('<item key="is_success">1</item><item key="response_code">211</item>')),
                str_contains($body, 'SW_REGISTER') => Http::response($reply('<item key="is_success">1</item><item key="response_code">200</item><item key="attributes"><dt_assoc><item key="id">9001</item></dt_assoc></item>')),
                default => Http::response($reply('<item key="is_success">0</item><item key="response_code">415</item><item key="response_text">Authentication Error.</item>')),
            };
        }]);

        $registrar = $this->registrar('opensrs', ['username' => 'rapidnet', 'api_key' => 'secret', 'mode' => 'test']);
        $domain = $this->domain('free.com');

        $this->assertSame(['free.com' => true, 'taken.com' => false], $registrar->checkAvailability(['free.com', 'taken.com']));

        $result = $registrar->register($domain, Contact::fromClient($domain->client));
        $this->assertTrue($result->success, $result->message);
        $this->assertSame('9001', $result->data['registrar_data']['order_id']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($body, 'SW_REGISTER')
                && $request->header('X-Signature')[0] === md5(md5($body.'secret').'secret')
                && str_contains($body, '<item key="phone">+964.7501234567</item>')
                && str_contains($body, '<item key="name">ns1.host.test</item>');
        });

        $this->assertSame('OpenSRS: Authentication Error.', $registrar->testConnection()->message);
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function registrar(string $slug, array $settings): DomainRegistrar
    {
        app(ExtensionManager::class)->saveSettings($slug, $settings, true);

        return app(ExtensionManager::class)->registrar($slug);
    }

    private function domain(string $name): Domain
    {
        $client = Client::factory()->create(['phone' => '+964 750 123 4567', 'country' => 'IQ', 'address_1' => '12 Cloud Street', 'city' => 'Erbil']);

        return Domain::factory()->pending()->create(['client_id' => $client->id, 'name' => $name, 'nameservers' => ['ns1.host.test', 'ns2.host.test']]);
    }

    private function namecheapXml(string $command, string $status = 'OK', string $errors = ''): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ApiResponse Status="'.$status.'" xmlns="http://api.namecheap.com/xml.response"><Errors>'.$errors.'</Errors><CommandResponse>'.$command.'</CommandResponse></ApiResponse>';
    }
}
