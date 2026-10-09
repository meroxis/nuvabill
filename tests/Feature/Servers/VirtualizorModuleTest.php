<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use stdClass;
use Tests\TestCase;

/**
 * The Virtualizor module: power and status, product options, credentials kept out of messages,
 * and the client's one-click sign-in to their own VPS panel.
 */
class VirtualizorModuleTest extends TestCase
{
    use RefreshDatabase;

    private const API_PASSWORD = 'S3cretApiPass99';

    /**
     * The fake Virtualizor of the running test: its answers and the requests it got.
     */
    private ?stdClass $api = null;

    public function test_an_unknown_state_shows_every_power_button_and_a_status_refresh(): void
    {
        $this->fakeVirtualizor(['status' => ['status' => []]]);
        $service = $this->service();

        $page = $this->actingAs($service->client, 'web')->get(route('client.services.show', $service))->assertOk();

        $page->assertSee('Unknown')->assertSee('Refresh status');

        foreach (['start', 'restart', 'stop', 'poweroff'] as $action) {
            $page->assertSee('formaction="'.route('client.services.panel', [$service, $action]).'"', false);
            $this->assertDoesNotMatchRegularExpression('/data-action="'.$action.'"[^>]*style="display: none"/s', $page->getContent());
        }

        $page->assertSee('needs ACPI support');
    }

    public function test_a_running_vps_hides_start_and_a_stopped_one_shows_only_start(): void
    {
        $this->fakeVirtualizor(['status' => ['status' => ['77' => ['status' => 1]]]]);
        $service = $this->service();
        $this->actingAs($service->client, 'web');

        $running = $this->get(route('client.services.show', $service))->getContent();
        $this->assertMatchesRegularExpression('/data-action="start"[^>]*style="display: none"/s', $running);
        $this->assertDoesNotMatchRegularExpression('/data-action="stop"[^>]*style="display: none"/s', $running);
        $this->assertStringNotContainsString('Refresh status</a>', preg_replace('/<a[^>]*style="display: none"[^>]*>.*?<\/a>/s', '', $running));

        $this->fakeVirtualizor(['status' => ['status' => ['77' => ['status' => 0]]]]);
        $stopped = $this->get(route('client.services.show', $service))->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-action="start"[^>]*style="display: none"/s', $stopped);
        $this->assertMatchesRegularExpression('/data-action="poweroff"[^>]*style="display: none"/s', $stopped);
    }

    public function test_power_buttons_answer_json_with_the_new_state_from_virtualizor(): void
    {
        $api = $this->fakeVirtualizor([
            'power' => fn (array $query): array => ['done' => true, 'done_msg' => 'Done', 'vsop' => ['action' => $query['action'], 'id' => '77', 'status' => ['77' => $query['action'] === 'start' ? 1 : 0]]],
        ]);
        $service = $this->service();
        $this->actingAs($service->client, 'web');

        $this->postJson(route('client.services.panel', [$service, 'start']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['ok' => true, 'message' => 'The VPS is starting.', 'pending' => 'start', 'state' => 'running']);

        $this->postJson(route('client.services.panel', [$service, 'stop']))
            ->assertOk()
            ->assertJson(['ok' => true, 'pending' => 'stop', 'state' => 'stopped'])
            ->assertJsonPath('message', 'A shutdown signal was sent. The VPS stops once its system has shut down. If it does not stop, use Power off.');

        $this->assertSame(['act' => 'vs', 'action' => 'start', 'vpsid' => '77'], $this->withoutCredentials($api->calls[0]['query']));
        $this->assertSame('GET', $api->calls[0]['method']);
    }

    public function test_without_javascript_the_page_after_a_power_action_waits_for_the_new_state(): void
    {
        $this->fakeVirtualizor([
            'power' => ['done' => true, 'vsop' => ['action' => 'start', 'id' => '77', 'status' => ['77' => 0]]],
            'status' => ['status' => ['77' => ['status' => 0]]],
        ]);
        $service = $this->service();
        $this->actingAs($service->client, 'web');

        $this->from(route('client.services.show', $service))->post(route('client.services.panel', [$service, 'start']))->assertRedirect(route('client.services.show', $service));
        $this->assertMatchesRegularExpression('#class="pill"\s+data-tone="info"\s+:data-tone="tone" x-text="label">Starting…</span>#u', $this->get(route('client.services.show', $service))->getContent());

        // Once the server got there, nothing waits.
        $this->fakeVirtualizor([
            'power' => ['done' => true, 'vsop' => ['action' => 'start', 'id' => '77', 'status' => ['77' => 1]]],
            'status' => ['status' => ['77' => ['status' => 1]]],
        ]);
        $this->post(route('client.services.panel', [$service, 'start']));
        $this->assertMatchesRegularExpression('#class="pill"\s+data-tone="good"\s+:data-tone="tone" x-text="label">Running</span>#u', $this->get(route('client.services.show', $service))->getContent());
    }

    public function test_a_failed_power_action_answers_json_without_details_of_the_server(): void
    {
        $this->fakeVirtualizor(['power' => ['error' => ['The VPS is locked']]]);
        $service = $this->service();

        $this->actingAs($service->client, 'web')
            ->postJson(route('client.services.panel', [$service, 'poweroff']))
            ->assertStatus(422)
            ->assertExactJson(['ok' => false, 'message' => 'Virtualizor: The VPS is locked', 'pending' => null, 'state' => null]);
    }

    public function test_the_status_route_gives_the_owner_the_live_state_only(): void
    {
        $this->fakeVirtualizor(['status' => ['status' => ['77' => ['status' => 0]]]]);
        $service = $this->service();

        $this->actingAs($this->client('Raz'), 'web')
            ->getJson(route('client.services.panel-status', $service))
            ->assertNotFound();

        $this->actingAs($service->client, 'web')
            ->getJson(route('client.services.panel-status', $service))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['state' => 'stopped']);

        $service->update(['status' => ServiceStatus::Suspended]);
        $this->getJson(route('client.services.panel-status', $service))->assertNotFound();

        $this->assertContains('throttle:30,1', app('router')->getRoutes()->getByName('client.services.panel-status')->gatherMiddleware());
    }

    public function test_the_api_key_and_password_never_reach_a_message_or_the_activity_log(): void
    {
        $leak = 'https://vz.example.test:4085/index.php?act=addvs&adminapikey=TESTTOKEN123&adminapipass='.self::API_PASSWORD;
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException("cURL error 28: Operation timed out (see https://curl.haxx.se) for {$leak}")]);
        $service = $this->service(attributes: ['status' => ServiceStatus::Pending, 'module_data' => null]);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('Could not connect to vz.example.test.', $result->message);
        $this->assertNoSecretsLogged();

        // An error from Virtualizor itself is kept for staff, without the key or password.
        $this->fakeVirtualizor(['addvs' => ['error' => ['Invalid key TESTTOKEN123 / '.self::API_PASSWORD.' <b>for</b> adminapipass='.self::API_PASSWORD]]]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid key [hidden]', $result->message);
        $this->assertStringNotContainsString('<b>', $result->message);
        $this->assertNoSecretsLogged();
    }

    public function test_plain_http_is_only_used_when_the_server_has_ssl_off(): void
    {
        $api = $this->fakeVirtualizor(['status' => ['status' => ['77' => ['status' => 1]]]]);
        $secure = $this->service();
        $plain = $this->service(server: ['use_ssl' => false, 'hostname' => 'vz2.example.test']);

        app(Provisioner::class)->clientPanel($secure);
        app(Provisioner::class)->clientPanel($plain);

        $this->assertTrue(collect($api->calls)->contains(fn (array $call): bool => str_starts_with($call['url'], 'https://vz.example.test:4085/')));
        $this->assertTrue(collect($api->calls)->contains(fn (array $call): bool => str_starts_with($call['url'], 'http://vz2.example.test:4085/')));
        $this->assertFalse(collect($api->calls)->contains(fn (array $call): bool => str_starts_with($call['url'], 'http://vz.example.test')));
    }

    public function test_a_server_hostname_that_is_not_a_hostname_is_refused(): void
    {
        $this->fakeVirtualizor([]);
        $service = $this->service(server: ['hostname' => 'evil.example.test/path?x=']);

        $result = app(Provisioner::class)->clientAction($service, 'start', []);

        $this->assertFalse($result->success);
        $this->assertSame('Check the hostname of the Virtualizor server.', $result->message);
        Http::assertNothingSent();
    }

    public function test_product_options_are_sent_with_a_new_vps(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '90', 'uid' => '58', 'ips' => ['203.0.113.9']]]]);
        $service = $this->service([
            'recipe_id' => '4',
            'num_ips6' => '2',
            'num_ips6_subnet' => '1',
            'network_speed' => '12800',
            'upload_speed' => '0',
            'server_group' => '3',
            'osreinstall_limit' => '5',
        ], ['status' => ServiceStatus::Pending, 'module_data' => null]);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);

        $data = $api->calls[0]['data'];
        $this->assertSame(4, $data['recipe']);
        $this->assertSame(2, $data['num_ips6']);
        $this->assertSame(1, $data['num_ips6_subnet']);
        $this->assertSame(12800, $data['network_speed']);
        $this->assertSame(0, $data['upload_speed']);
        $this->assertSame(3, $data['server_group']);
        $this->assertSame(5, $data['osreinstall_limit']);
        $this->assertSame(1, $data['node_select']);
        $this->assertArrayNotHasKey('control_panel', $data);
    }

    public function test_a_chosen_virtualizor_server_replaces_the_automatic_choice(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '90']]]);
        $service = $this->service(['slave_server' => '0'], ['status' => ServiceStatus::Pending, 'module_data' => null]);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);

        $this->assertSame(0, $api->calls[0]['data']['slave_server']);
        $this->assertArrayNotHasKey('node_select', $api->calls[0]['data']);
    }

    public function test_invalid_product_options_stop_the_create_before_anything_is_sent(): void
    {
        $this->fakeVirtualizor([]);

        foreach ([
            [['recipe_id' => '0'], 'Set "Recipe ID" on the product to a whole number of 1 or more, or leave it empty.'],
            [['recipe_id' => '2; rm'], 'Set "Recipe ID" on the product to a whole number of 1 or more, or leave it empty.'],
            [['network_speed' => '-5'], 'Set "Network speed" on the product to a whole number of 0 or more, or leave it empty.'],
            [['control_panel' => 'directadmin'], 'Choose a control panel from the list on the product, or none.'],
            [['control_panel' => 'cpanel', 'recipe_id' => '3'], 'Choose either a recipe or a control panel on the product, not both.'],
        ] as [$config, $message]) {
            $service = $this->service($config, ['status' => ServiceStatus::Pending, 'module_data' => null, 'domain' => 'server.example.com']);
            $result = app(Provisioner::class)->create($service);

            $this->assertFalse($result->success);
            $this->assertSame($message, $result->message);
        }

        Http::assertNothingSent();
    }

    public function test_a_control_panel_needs_a_real_hostname_and_cpanel_has_stricter_rules(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '91']]]);

        foreach (['www.example.com', 'cpanel.example.com', 'vps.localdomain', str_repeat('a', 50).'.example.com'] as $domain) {
            $service = $this->service(['control_panel' => 'cpanel'], ['status' => ServiceStatus::Pending, 'module_data' => null, 'domain' => $domain]);
            $this->assertFalse(app(Provisioner::class)->create($service)->success, $domain);
        }

        $this->assertSame([], $api->calls);

        $plesk = $this->service(['control_panel' => 'plesk'], ['status' => ServiceStatus::Pending, 'module_data' => null, 'domain' => 'www.example.com']);
        $this->assertTrue(app(Provisioner::class)->create($plesk)->success);
        $this->assertSame('plesk', $api->calls[0]['data']['control_panel']);
        $this->assertSame('www.example.com', $api->calls[0]['data']['hostname']);

        $cpanel = $this->service(['control_panel' => 'cpanel'], ['status' => ServiceStatus::Pending, 'module_data' => null, 'domain' => 'Server1.Example.com']);
        $this->assertTrue(app(Provisioner::class)->create($cpanel)->success);
        $this->assertSame('cpanel', $api->calls[1]['data']['control_panel']);
        $this->assertSame('server1.example.com', $api->calls[1]['data']['hostname']);
    }

    public function test_a_reinstall_runs_the_products_recipe_again_only_when_asked(): void
    {
        $api = $this->fakeVirtualizor(['rebuild' => ['done' => 1]]);
        $service = $this->service(['recipe_id' => '4']);
        $this->actingAs($service->client, 'web');

        $this->get(route('client.services.show', $service))->assertSee('Run the setup script of your plan again');

        $this->post(route('client.services.panel', [$service, 'reinstall']), ['os_id' => '100', 'password' => 'Secret12345', 'recipe' => '99'])->assertSessionHas('status');
        $this->post(route('client.services.panel', [$service, 'reinstall']), ['os_id' => '100', 'password' => 'Secret12345', 'run_recipe' => '1'])->assertSessionHas('status');

        $rebuilds = collect($api->calls)->where('kind', 'rebuild')->values();
        $this->assertArrayNotHasKey('recipe', $rebuilds[0]['data']);
        $this->assertSame(4, $rebuilds[1]['data']['recipe']);
    }

    public function test_suspend_unsuspend_terminate_hostname_and_password_still_work(): void
    {
        $api = $this->fakeVirtualizor([
            'suspend' => ['done' => 1],
            'unsuspend' => ['done' => 1],
            'delete' => ['done' => 1],
            'managevps' => ['done' => ['done' => true], 'vs_info' => ['vpsid' => '77']],
        ]);
        $service = $this->service();
        $provisioner = app(Provisioner::class);

        $this->assertTrue($provisioner->clientAction($service, 'hostname', ['hostname' => 'new.example.com'])->success);
        $this->assertTrue($provisioner->clientAction($service, 'password', ['password' => 'Better12345'])->success);
        $this->assertFalse($provisioner->clientAction($service, 'hostname', ['hostname' => 'bad host'])->success);
        $this->assertTrue($provisioner->suspend($service, 'Overdue on payment')->success);
        $this->assertTrue($provisioner->unsuspend($service->fresh())->success);
        $this->assertTrue($provisioner->terminate($service->fresh())->success);

        $this->assertSame(['managevps', 'managevps', 'suspend', 'unsuspend', 'delete'], array_column($api->calls, 'kind'));
        $this->assertSame('new.example.com', $api->calls[0]['data']['hostname']);
        $this->assertSame('Better12345', $api->calls[1]['data']['rootpass']);
        $this->assertSame('Overdue on payment', $api->calls[2]['data']['suspend_reason']);
        $this->assertSame(['act' => 'vs', 'delete' => '77'], $this->withoutCredentials($api->calls[4]['query']));
    }

    public function test_without_panel_sign_in_clients_keep_the_vnc_details_and_get_no_link(): void
    {
        $this->fakeVirtualizor(['vnc' => ['info' => ['ip' => '198.51.100.2', 'port' => 5901, 'password' => 'vncpass1']]]);
        $service = $this->service();
        $this->actingAs($service->client, 'web');

        $this->get(route('client.services.show', $service))->assertSee('Show VNC details')->assertDontSee('Open Virtualizor panel');
        $this->post(route('client.services.panel', [$service, 'vnc']))->assertSessionHas('panel_result.vnc.port', '5901');

        $this->post(route('client.services.login', $service))->assertSessionHas('error');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'act=sso'));
    }

    public function test_clients_open_their_own_virtualizor_panel_with_one_click(): void
    {
        $api = $this->fakeVirtualizor([
            'sso' => ['token_key' => 'sessvkD9MBpOg0aFkHfD', 'sid' => 'efgBrcHtvyVEGcvaeI9q2A9X4kaOAU18'],
        ]);
        $service = $this->service(['client_login' => '1'], ['module_data' => ['vpsid' => '77', 'uid' => '58']]);
        $this->actingAs($service->client, 'web');

        $this->get(route('client.services.show', $service))
            ->assertSee('Open Virtualizor panel')
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSee('Show VNC details')
            ->assertDontSee('Open control panel');

        $api->calls = [];

        $this->post(route('client.services.login', $service))
            ->assertRedirect('https://vz.example.test:4083/sessvkD9MBpOg0aFkHfD/?as=efgBrcHtvyVEGcvaeI9q2A9X4kaOAU18&svs=77')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $sso = collect($api->calls)->firstWhere('kind', 'sso');
        $this->assertStringStartsWith('https://vz.example.test:4083/index.php?', $sso['url']);
        $this->assertSame(['act', 'svs', 'api', 'apikey'], array_keys($sso['query']));
        $this->assertSame('77', $sso['query']['svs']);
        // Signed like Virtualizor's SDK does for admins: the API password itself is never sent.
        $salt = substr($sso['query']['apikey'], 0, 8);
        $this->assertSame($salt.md5(self::API_PASSWORD.$salt), $sso['query']['apikey']);
        $this->assertStringNotContainsString(self::API_PASSWORD, $sso['url']);
        $this->assertStringNotContainsString('TESTTOKEN123', $sso['url']);

        // The owner is checked live before the link is made, and the link itself is never logged.
        $this->assertSame(['vps', 'users', 'sso'], array_column($api->calls, 'kind'));
        $this->assertSame(['act' => 'vs', 'search' => '1', 'vpsid' => '77', 'page' => '1', 'reslen' => '1'], $this->withoutCredentials($api->calls[0]['query']));
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%sessvkD9MBpOg0aFkHfD%')->exists());
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%efgBrcHtvyVEGcvaeI9q2A9X4kaOAU18%')->exists());
        $this->assertTrue(ActivityLog::query()->where('action', 'service.panel_login')->exists());

        $this->post(route('client.services.panel', [$service, 'vnc']))->assertSessionHas('error', 'Open the Virtualizor panel to use the console in your browser.');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'act=vnc'));

        $this->assertContains('throttle:10,1', app('router')->getRoutes()->getByName('client.services.login')->gatherMiddleware());
    }

    public function test_the_panel_port_can_be_set_on_the_product(): void
    {
        $api = $this->fakeVirtualizor(['sso' => ['token_key' => 'sessAbcdefgh123', 'sid' => 'sidAbcdefgh123']]);
        $service = $this->service(['client_login' => '1', 'panel_port' => '8443'], ['module_data' => ['vpsid' => '77', 'uid' => '58']]);

        $this->assertSame('https://vz.example.test:8443/sessAbcdefgh123/?as=sidAbcdefgh123&svs=77', app(Provisioner::class)->loginUrl($service));
        $this->assertStringStartsWith('https://vz.example.test:8443/index.php?', collect($api->calls)->firstWhere('kind', 'sso')['url']);
    }

    public function test_panel_sign_in_is_refused_when_the_vps_does_not_belong_to_the_client_alone(): void
    {
        $sso = ['token_key' => 'sessvkD9MBpOg0aFkHfD', 'sid' => 'efgBrcHtvyVEGcvaeI9q2A9X4kaOAU18'];
        $cases = [
            'another user now' => [['uid' => '99'], ['users' => ['99' => ['uid' => '99', 'type' => '0', 'numvps' => '1']]]],
            'suspended' => [['suspended' => '1'], null],
            'an admin user' => [[], ['users' => ['58' => ['uid' => '58', 'type' => '1', 'numvps' => '1']]]],
            'a cloud user' => [[], ['users' => ['58' => ['uid' => '58', 'type' => '2', 'numvps' => '1']]]],
            'more VPS than the client has' => [[], ['users' => ['58' => ['uid' => '58', 'type' => '0', 'numvps' => '2']]]],
            'no VPS count' => [[], ['users' => ['58' => ['uid' => '58', 'type' => '0']]]],
        ];

        foreach ($cases as $case => [$vps, $users]) {
            $api = $this->fakeVirtualizor(array_filter([
                'vps' => fn (array $query): array => ['vs' => [$query['vpsid'] => $vps + ['vpsid' => $query['vpsid'], 'uid' => '58', 'suspended' => '0']]],
                'users' => $users,
                'sso' => $sso,
            ]));
            $service = $this->service(['client_login' => '1'], ['module_data' => ['vpsid' => '77', 'uid' => '58']]);

            $this->assertNull(app(Provisioner::class)->loginUrl($service), $case);
            $this->assertNotContains('sso', array_column($api->calls, 'kind'), $case);
        }

        $this->assertSame(count($cases), ActivityLog::query()->where('action', 'service.panel_login_refused')->count());
    }

    public function test_a_user_with_another_vps_of_the_same_client_may_sign_in_but_not_with_someone_elses(): void
    {
        $owners = ['77' => '58', '78' => '58', '79' => '61'];
        $api = $this->fakeVirtualizor([
            'vps' => function (array $query) use (&$owners): array {
                return ['vs' => [$query['vpsid'] => ['vpsid' => $query['vpsid'], 'uid' => $owners[$query['vpsid']], 'suspended' => '0']]];
            },
            'users' => ['users' => ['58' => ['uid' => '58', 'type' => '0', 'numvps' => '2']]],
            'sso' => ['token_key' => 'sessvkD9MBpOg0aFkHfD', 'sid' => 'efgBrcHtvyVEGcvaeI9q2A9X4kaOAU18'],
        ]);
        $service = $this->service(['client_login' => '1'], ['module_data' => ['vpsid' => '77', 'uid' => '58']]);
        $this->service(['client_login' => '1'], ['client_id' => $service->client_id, 'module_data' => ['vpsid' => '78']], server: $service->server);

        $this->assertNotNull(app(Provisioner::class)->loginUrl($service));

        // The second VPS of that Virtualizor user belongs to another client.
        $owners['78'] = '61';
        $owners['79'] = '58';
        $other = $this->service(['client_login' => '1'], ['client_id' => $this->client('Raz')->id, 'module_data' => ['vpsid' => '79']], server: $service->server);
        $this->assertNotNull($other);
        $api->calls = [];

        $this->assertNull(app(Provisioner::class)->loginUrl($service));
        $this->assertNotContains('sso', array_column($api->calls, 'kind'));
    }

    public function test_an_older_service_without_a_stored_user_gets_it_once_the_check_passes(): void
    {
        $this->fakeVirtualizor(['sso' => ['token_key' => 'sessvkD9MBpOg0aFkHfD', 'sid' => 'efgBrcHtvyVEGcvaeI9q2A9X4kaOAU18']]);
        $service = $this->service(['client_login' => '1'], ['module_data' => ['vpsid' => '77']]);

        $this->assertNotNull(app(Provisioner::class)->loginUrl($service));
        $this->assertSame('58', $service->fresh()->module_data['uid']);
    }

    public function test_panel_sign_in_needs_ssl_an_active_service_and_a_sign_in_session(): void
    {
        $api = $this->fakeVirtualizor(['sso' => ['token_key' => 'x', 'sid' => '']]);

        $plain = $this->service(['client_login' => '1'], ['module_data' => ['vpsid' => '77', 'uid' => '58']], ['use_ssl' => false]);
        $this->assertNull(app(Provisioner::class)->loginUrl($plain));
        $this->assertSame([], $api->calls);

        $suspended = $this->service(['client_login' => '1'], ['status' => ServiceStatus::Suspended, 'module_data' => ['vpsid' => '77', 'uid' => '58']]);
        $this->assertNull(app(Provisioner::class)->loginUrl($suspended));
        $this->assertSame([], $api->calls);

        $badPort = $this->service(['client_login' => '1', 'panel_port' => '99999'], ['module_data' => ['vpsid' => '77', 'uid' => '58']]);
        $this->assertNull(app(Provisioner::class)->loginUrl($badPort));
        $this->assertSame([], $api->calls);

        // A reply without a usable session gives no link.
        $service = $this->service(['client_login' => '1'], ['module_data' => ['vpsid' => '77', 'uid' => '58']]);
        $this->assertNull(app(Provisioner::class)->loginUrl($service));
        $this->assertContains('sso', array_column($api->calls, 'kind'));
    }

    public function test_another_client_cannot_open_the_panel(): void
    {
        $this->fakeVirtualizor([]);
        $service = $this->service(['client_login' => '1'], ['module_data' => ['vpsid' => '77', 'uid' => '58']]);

        $this->actingAs($this->client('Raz'), 'web')
            ->post(route('client.services.login', $service))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * A fake Virtualizor. Answers go by the kind of request (see kind()); each one is an array, a
     * response, or a closure given the query and the posted data. The result lists every request
     * in "calls" as kind, query, data, method and URL. A second call swaps the answers.
     *
     * @param  array<string, mixed>  $answers
     */
    private function fakeVirtualizor(array $answers): stdClass
    {
        $answers += [
            'vps' => fn (array $query): array => ['vs' => [$query['vpsid'] => ['vpsid' => $query['vpsid'], 'uid' => '58', 'suspended' => '0', 'hostname' => 'vps1.example.com', 'os_name' => 'Ubuntu 24.04', 'virt' => 'kvm', 'ips' => ['2' => '203.0.113.7']]]],
            'status' => ['status' => ['77' => ['status' => 1]]],
            'ostemplates' => ['ostemplates' => ['100' => ['osid' => 100, 'type' => 'kvm', 'name' => 'Ubuntu 24.04']]],
            'users' => ['users' => ['58' => ['uid' => '58', 'type' => '0', 'numvps' => '1']]],
        ];
        if ($this->api === null) {
            $this->api = new stdClass;

            Http::fake(function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $kind = $this->kind($query);
                $this->api->calls[] = ['kind' => $kind, 'query' => $query, 'data' => $request->data(), 'method' => $request->method(), 'url' => $request->url()];
                $answer = $this->api->answers[$kind] ?? ['error' => ['Unexpected request: '.$kind]];
                $answer = $answer instanceof Closure ? $answer($query, $request->data()) : $answer;

                return is_array($answer) ? Http::response($answer) : $answer;
            });
        }

        $this->api->answers = $answers;
        $this->api->calls = [];

        return $this->api;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function kind(array $query): string
    {
        $act = (string) ($query['act'] ?? '');

        return match (true) {
            $act !== 'vs' => $act,
            isset($query['vs_status']) => 'status',
            isset($query['action']) => 'power',
            isset($query['vpshostname']) => 'byname',
            isset($query['suspend']) => 'suspend',
            isset($query['unsuspend']) => 'unsuspend',
            isset($query['delete']) => 'delete',
            isset($query['vpsid']) => 'vps',
            default => 'list',
        };
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withoutCredentials(array $query): array
    {
        return array_diff_key($query, array_flip(['api', 'adminapikey', 'adminapipass']));
    }

    private function assertNoSecretsLogged(): void
    {
        foreach (ActivityLog::query()->pluck('description') as $description) {
            $this->assertStringNotContainsString('TESTTOKEN123', $description);
            $this->assertStringNotContainsString(self::API_PASSWORD, $description);
        }
    }

    private function client(string $name = 'Mer Las'): Client
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        return Client::factory()->create(['first_name' => $first, 'last_name' => $last, 'company_name' => null]);
    }

    /**
     * A Virtualizor service of Mer Las with VPS 77, on a Virtualizor server with SSL.
     *
     * @param  array<string, string>  $config
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|Server  $server
     */
    private function service(array $config = [], array $attributes = [], array|Server $server = []): Service
    {
        $server = $server instanceof Server ? $server : Server::factory()->create($server + [
            'module' => 'virtualizor',
            'hostname' => 'vz.example.test',
            'port' => 4085,
            'password' => self::API_PASSWORD,
        ]);
        $product = Product::factory()->create(['server_module' => 'virtualizor', 'server_id' => $server->id, 'module_config' => $config + ['virt' => 'kvm', 'os_id' => '100']]);

        return Service::factory()->create($attributes + [
            'client_id' => $this->client()->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'username' => 'root',
            'module_data' => ['vpsid' => '77'],
        ]);
    }
}
