<?php

namespace Tests\Feature;

use App\Chat\ChatMessages;
use App\Chat\LinkCodes;
use App\Chat\WhatsApp;
use App\Chat\WhatsAppSetup;
use App\Mail\TemplatedMessage;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\ChatLink;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChatAppsTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = '123456789:AAFtestTokenForTheBotFatherBotAbcdefg';

    private const SECRET = 'telegramSecret0123456789';

    private const WHATSAPP_KEY = 'webhookKey0123456789abcdefghijklmnopqrstuvwxyzAB';

    public function test_the_owner_connects_a_telegram_bot_and_its_webhook_points_here(): void
    {
        $this->signInAdmin();
        Http::fake([
            'api.telegram.org/*' => fn (Request $request) => Http::response(str_ends_with($request->url(), '/getMe')
                ? ['ok' => true, 'result' => ['username' => 'YourHostBot']]
                : ['ok' => true, 'result' => true]),
            'my.nuvabill.com/*' => Http::response(['ready' => true]),
        ]);

        $this->put(route('admin.settings.chat.telegram'), ['token' => 'nope'])->assertSessionHasErrors('token');
        $this->put(route('admin.settings.chat.telegram'), ['token' => self::BOT])->assertSessionHas('status', 'Telegram is connected as @YourHostBot.');

        $this->assertSame('YourHostBot', setting('chat.telegram_bot'));
        $this->assertSame(self::BOT, setting('chat.telegram_token'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === route('webhooks.telegram')
            && $request['secret_token'] === setting('chat.telegram_secret'));

        $this->get(route('admin.settings.chat.edit'))->assertOk()->assertSee('Connected as @YourHostBot');
    }

    public function test_a_client_links_telegram_with_the_qr_code_and_gets_a_welcome(): void
    {
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $client = Client::factory()->create(['first_name' => 'Raz']);

        $this->actingAs($client, 'web')->get(route('client.account.edit'))->assertOk()
            ->assertSee('Get alerts on your phone')
            ->assertSee('https://t.me/YourHostBot?start='.LinkCodes::for($client), false);

        $this->telegramUpdate(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'from' => ['first_name' => 'Raz'], 'text' => '/start '.LinkCodes::for($client)]])->assertOk();

        $link = ChatLink::query()->sole();
        $this->assertSame($client->id, $link->client_id);
        $this->assertSame('555', $link->external_id);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '555' && str_contains($request['text'], 'This chat is now connected'));
    }

    public function test_telegram_updates_without_the_secret_are_refused(): void
    {
        $this->connectTelegram();

        $this->postJson(route('webhooks.telegram'), ['message' => ['chat' => ['id' => 1, 'type' => 'private'], 'text' => '/help']])->assertForbidden();
        $this->postJson(route('webhooks.telegram'), [], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
    }

    public function test_messages_from_a_linked_client_become_a_ticket_and_follow_ups_join_it(): void
    {
        Mail::fake();
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $link = ChatLink::create(['client_id' => Client::factory()->create()->id, 'channel' => 'telegram', 'external_id' => '555']);

        $this->telegramUpdate(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => "My email stopped working\nSince this morning."]]);
        $ticket = Ticket::query()->sole();
        $this->assertSame('My email stopped working', $ticket->subject);
        $this->assertSame('telegram', $ticket->replies()->sole()->channel);

        $this->telegramUpdate(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => 'Here is more detail.']]);
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(2, $ticket->replies()->count());
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Added to ticket #'.$ticket->number));
        $this->assertNotNull($link->fresh()->last_inbound_at);
    }

    public function test_commands_answer_with_the_clients_own_invoices(): void
    {
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $client = Client::factory()->create();
        $invoice = Invoice::factory()->for($client)->create(['number' => 'INV-1042']);
        ChatLink::create(['client_id' => $client->id, 'channel' => 'telegram', 'external_id' => '555']);

        $this->telegramUpdate(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => '/invoices']]);

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Your unpaid invoices') && str_contains((string) $request['text'], $invoice->displayNumber()));
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_a_staff_reply_reaches_the_clients_telegram_with_a_button(): void
    {
        Mail::fake();
        // Telegram buttons only take https links.
        URL::forceRootUrl('https://billing.example.com');
        URL::forceScheme('https');
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $client = Client::factory()->create(['first_name' => 'Raz']);
        ChatLink::create(['client_id' => $client->id, 'channel' => 'telegram', 'external_id' => '555']);
        $ticket = app(TicketDesk::class)->open($client, TicketDepartment::query()->firstOrFail(), 'SSL', 'Not secure warning');

        app(TicketDesk::class)->replyAsStaff($ticket, Admin::factory()->create(), 'We renewed the certificate. Please check again.');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'We renewed the certificate')
            && ($request['reply_markup']['inline_keyboard'][0][0]['url'] ?? '') === route('client.tickets.show', $ticket));
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($client->email));
    }

    public function test_messages_the_owner_switched_off_are_not_sent(): void
    {
        Mail::fake();
        $this->connectTelegram();
        Http::fake();
        $this->setSettings(['chat.events' => ['ticket.reply' => ['telegram' => false, 'whatsapp' => false]]]);
        $client = Client::factory()->create();
        ChatLink::create(['client_id' => $client->id, 'channel' => 'telegram', 'external_id' => '555']);

        app(TemplateMailer::class)->send('ticket.reply', $client, ['reply' => ['message' => 'Hi']]);

        Http::assertNothingSent();
    }

    public function test_whatsapp_uses_an_approved_template_outside_the_24_hour_window_and_free_text_inside_it(): void
    {
        Mail::fake();
        $this->connectWhatsApp(['nuvabill_invoice_created' => ['en' => 'APPROVED']]);
        $this->setSettings(['chat.events' => ['invoice.created' => ['telegram' => false, 'whatsapp' => true]]]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $client = Client::factory()->create(['first_name' => 'Raz', 'language' => 'en']);
        $link = ChatLink::create(['client_id' => $client->id, 'channel' => 'whatsapp', 'external_id' => '9647501234567']);
        $invoice = Invoice::factory()->for($client)->create(['number' => 'INV-1042', 'total' => 1299]);

        app(TemplateMailer::class)->send('invoice.created', $client, TemplateMailer::invoiceContext($invoice));

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'template'
            && $request['to'] === '9647501234567'
            && $request['template']['name'] === 'nuvabill_invoice_created'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Raz'
            && $request['template']['components'][0]['parameters'][1]['text'] === $invoice->displayNumber()
            && $request['template']['components'][1]['parameters'][0]['text'] === 'client/invoices/'.$invoice->id);

        $link->update(['last_inbound_at' => now()->subHour()]);
        app(TemplateMailer::class)->send('invoice.created', $client, TemplateMailer::invoiceContext($invoice));

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'text' && str_contains($request['text']['body'], 'is ready. It is due on'));
    }

    public function test_an_addon_that_connects_whatsapp_sends_free_text_any_time_and_shows_on_the_settings_page(): void
    {
        Mail::fake();
        $this->setSettings(['chat.events' => ['invoice.created' => ['telegram' => false, 'whatsapp' => true]]]);
        // Shaped like WhatsApp by QR code: a number linked through a bridge, no Meta templates.
        $whatsApp = new class extends WhatsApp
        {
            /** @var list<array{to: string, text: string}> */
            public array $sent = [];

            public function isConnected(): bool
            {
                return true;
            }

            public function number(): string
            {
                return '9647501234567';
            }

            public function sendsFreeTextAnytime(): bool
            {
                return true;
            }

            public function connectedThrough(): ?array
            {
                return ['name' => 'WhatsApp by QR Code', 'url' => url('admin/addons/whatsapp-qr'), 'number' => '+964 750 123 4567'];
            }

            public function sendText(string $to, string $text): void
            {
                $this->sent[] = ['to' => $to, 'text' => $text];
            }
        };
        $this->app->instance(WhatsApp::class, $whatsApp);

        $client = Client::factory()->create(['first_name' => 'Raz', 'language' => 'en']);
        ChatLink::create(['client_id' => $client->id, 'channel' => 'whatsapp', 'external_id' => '9647501234567']);
        $invoice = Invoice::factory()->for($client)->create(['number' => 'INV-1042', 'total' => 1299]);

        // The client never wrote in the last 24 hours, and no template is approved: free text anyway.
        app(TemplateMailer::class)->send('invoice.created', $client, TemplateMailer::invoiceContext($invoice));

        $this->assertCount(1, $whatsApp->sent);
        $this->assertSame('9647501234567', $whatsApp->sent[0]['to']);
        $this->assertStringContainsString('is ready. It is due on', $whatsApp->sent[0]['text']);

        $this->signInAdmin();
        $this->get(route('admin.settings.chat.edit'))->assertOk()
            ->assertSee('Connected: +964 750 123 4567')
            ->assertSee('WhatsApp messages go through the WhatsApp by QR Code add-on.')
            ->assertSee(url('admin/addons/whatsapp-qr'), false)
            ->assertDontSee('Message templates');
    }

    public function test_the_whatsapp_webhook_address_is_verified_and_links_a_client(): void
    {
        $this->connectWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $client = Client::factory()->create();
        $code = LinkCodes::for($client);

        $this->get(route('webhooks.whatsapp', self::WHATSAPP_KEY).'?hub.mode=subscribe&hub.verify_token='.self::WHATSAPP_KEY.'&hub.challenge=4242')
            ->assertOk()->assertSee('4242');
        $this->get(route('webhooks.whatsapp', 'x'.substr(self::WHATSAPP_KEY, 1)).'?hub.mode=subscribe&hub.verify_token=x&hub.challenge=1')->assertNotFound();

        $this->postJson(route('webhooks.whatsapp', self::WHATSAPP_KEY), $this->whatsappMessage('9647501234567', 'LINK '.$code, 'Raz'))->assertOk();

        $this->assertSame($client->id, ChatLink::query()->where('channel', 'whatsapp')->sole()->client_id);
        Http::assertSent(fn (Request $request): bool => $request['to'] === '9647501234567' && str_contains($request['text']['body'], 'This chat is now connected'));
    }

    public function test_unlinked_whatsapp_senders_are_left_to_the_owner(): void
    {
        $this->connectWhatsApp();
        Http::fake();

        $this->postJson(route('webhooks.whatsapp', self::WHATSAPP_KEY), $this->whatsappMessage('9647500000000', 'Hi, do you sell domains?'))->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_with_the_owners_own_meta_app_the_signature_is_checked(): void
    {
        $this->connectWhatsApp();
        $this->setSettings(['chat.whatsapp_app_secret' => str_repeat('a', 32)]);
        $body = json_encode($this->whatsappMessage('9647500000000', 'help'));

        $this->call('POST', route('webhooks.whatsapp', self::WHATSAPP_KEY), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertForbidden();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => []])]);
        $signature = 'sha256='.hash_hmac('sha256', (string) $body, str_repeat('a', 32));
        $this->call('POST', route('webhooks.whatsapp', self::WHATSAPP_KEY), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $body)->assertOk();
    }

    public function test_connecting_by_qr_code_routes_webhooks_here_syncs_the_business_app_and_asks_for_templates(): void
    {
        // Template buttons need an https site address.
        URL::forceRootUrl('https://billing.example.com');
        URL::forceScheme('https');
        $this->signInAdmin();
        Http::fake(['my.nuvabill.com/connect/whatsapp/status' => Http::response(['ready' => true])]);
        $this->get(route('admin.settings.chat.edit'))->assertOk()->assertSee('Connect with a QR code');
        $state = session('chat.whatsapp_state');
        Http::fake(['graph.facebook.com/*' => fn (Request $request) => Http::response(match (true) {
            str_contains($request->url(), '/phone_numbers') => ['data' => [['id' => '1001', 'display_phone_number' => '+964 750 123 4567', 'verified_name' => 'YourHost']]],
            str_contains($request->url(), 'message_templates') && $request->method() === 'GET' => ['data' => [['name' => 'nuvabill_invoice_created', 'language' => 'en', 'status' => 'PENDING']]],
            default => ['success' => true],
        })]);

        $this->postJson(route('admin.settings.chat.whatsapp.connect'), ['state' => 'wrong', 'token' => 'EAAB', 'waba_id' => '102290129340398'])->assertStatus(422);
        // A wrong answer uses up the one-time state, so the page must be opened again.
        $this->get(route('admin.settings.chat.edit'));
        $state = session('chat.whatsapp_state');
        $this->postJson(route('admin.settings.chat.whatsapp.connect'), ['state' => $state, 'token' => 'EAABtoken', 'waba_id' => '102290129340398', 'business_app' => true])->assertOk();

        $this->assertSame('1001', setting('chat.whatsapp_phone_id'));
        $this->assertSame('+964 750 123 4567', setting('chat.whatsapp_number'));
        $this->assertSame('qr', setting('chat.whatsapp_via'));
        $this->assertSame(['nuvabill_invoice_created' => ['en' => 'PENDING']], setting('chat.whatsapp_templates'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '102290129340398/subscribed_apps')
            && $request['override_callback_uri'] === route('webhooks.whatsapp', setting('chat.whatsapp_webhook_key'))
            && $request['verify_token'] === setting('chat.whatsapp_webhook_key'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '1001/smb_app_data') && $request['sync_type'] === 'history');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '102290129340398/message_templates') && $request->method() === 'POST'
            && $request['name'] === 'nuvabill_ticket_reply' && str_contains($request['components'][0]['text'], '{{1}}')
            && $request['components'][1]['buttons'][0]['url'] === url('/').'/{{1}}');
    }

    public function test_meta_template_decisions_and_disconnections_arrive_by_webhook(): void
    {
        $this->connectWhatsApp();

        $this->postJson(route('webhooks.whatsapp', self::WHATSAPP_KEY), ['entry' => [['changes' => [['field' => 'message_template_status_update', 'value' => [
            'event' => 'APPROVED', 'message_template_name' => 'nuvabill_service_ready', 'message_template_language' => 'en',
        ]]]]]])->assertOk();
        $this->assertSame('APPROVED', setting('chat.whatsapp_templates')['nuvabill_service_ready']['en']);

        $this->postJson(route('webhooks.whatsapp', self::WHATSAPP_KEY), ['entry' => [['changes' => [['field' => 'account_update', 'value' => [
            'event' => 'PARTNER_REMOVED',
        ]]]]]])->assertOk();
        $this->assertSame('', setting('chat.whatsapp_token'));
    }

    public function test_the_connect_page_runs_meta_signup_only_where_meta_keys_are_set(): void
    {
        $query = '?origin='.urlencode('https://billing.example.com/').'&state='.str_repeat('a', 32);

        $this->get(route('connect.whatsapp').$query)->assertOk()->assertSee('not available yet')->assertDontSee('connect.facebook.net');
        $this->getJson(route('connect.whatsapp.status'))->assertOk()->assertExactJson(['ready' => false]);

        config(['nuvabill.whatsapp_connect.app_id' => '111', 'nuvabill.whatsapp_connect.app_secret' => 'shh', 'nuvabill.whatsapp_connect.config_id' => '222']);
        // Keys alone let the store's own staff try it; other sites wait until it is opened.
        $this->getJson(route('connect.whatsapp.status'))->assertExactJson(['ready' => false]);
        config(['nuvabill.whatsapp_connect.open' => true]);
        $this->getJson(route('connect.whatsapp.status'))->assertExactJson(['ready' => true]);

        $page = $this->get(route('connect.whatsapp').$query)->assertOk()->assertSee('connect.facebook.net')->assertSee('billing.example.com');
        $this->assertStringContainsString('https://connect.facebook.net', (string) $page->headers->get('Content-Security-Policy'));
        $this->assertSame('unsafe-none', $page->headers->get('Cross-Origin-Opener-Policy'));
        $this->get(route('connect.whatsapp').'?origin='.urlencode('javascript:alert(1)').'&state='.str_repeat('a', 32))->assertSee('must be opened from Settings');

        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'EAABbusiness'])]);
        $this->postJson(route('connect.whatsapp.exchange'), ['code' => 'abc'])->assertOk()->assertJson(['token' => 'EAABbusiness']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth/access_token') && $request['client_secret'] === 'shh' && $request['code'] === 'abc');
    }

    public function test_until_the_store_can_run_meta_signup_the_owner_is_sent_to_their_own_meta_app(): void
    {
        $this->signInAdmin();
        Http::fake(['my.nuvabill.com/*' => Http::sequence()->push(['ready' => false])->push('Not found', 404)]);

        $this->get(route('admin.settings.chat.edit'))->assertOk()
            ->assertSee('Connecting by QR code is not available yet')
            ->assertDontSee('Connect with a QR code')
            ->assertSee('Permanent access token');

        // A store that is down or has no connect page counts as not ready.
        Cache::flush();
        $this->get(route('admin.settings.chat.edit'))->assertSee('Connecting by QR code is not available yet');
    }

    public function test_the_stores_meta_app_webhook_is_verified_and_only_takes_signed_posts(): void
    {
        $this->get(route('webhooks.meta').'?hub_mode=subscribe&hub_verify_token=x&hub_challenge=1')->assertNotFound();

        config(['nuvabill.whatsapp_connect.app_secret' => 'shh', 'nuvabill.whatsapp_connect.verify_token' => 'checkMe']);

        $this->get(route('webhooks.meta').'?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=42')->assertForbidden();
        $this->get(route('webhooks.meta').'?hub_mode=subscribe&hub_verify_token=checkMe&hub_challenge=42')->assertOk()->assertSeeText('42');

        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        $this->call('POST', route('webhooks.meta'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
        $this->call('POST', route('webhooks.meta'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'shh')], $body)->assertOk();
    }

    public function test_sites_keep_asking_meta_about_templates_until_all_are_decided(): void
    {
        $setup = app(WhatsAppSetup::class);
        $this->assertFalse($setup->hasUndecidedTemplates());

        $this->connectWhatsApp(['nuvabill_invoice_created' => ['en' => 'APPROVED']]);
        $this->assertTrue($setup->hasUndecidedTemplates());

        $decided = collect(ChatMessages::EVENTS)->mapWithKeys(fn (array $event): array => [$event['template'] => ['en' => 'APPROVED']])->all();
        $decided['nuvabill_ticket_reply']['en'] = 'REJECTED';
        $this->connectWhatsApp($decided);
        $this->assertFalse($setup->hasUndecidedTemplates());

        $decided['nuvabill_domain_expiring']['en'] = 'PENDING';
        $this->connectWhatsApp($decided);
        $this->assertTrue($setup->hasUndecidedTemplates());
    }

    public function test_staff_alerts_go_to_the_team_group(): void
    {
        Mail::fake();
        URL::forceRootUrl('https://billing.example.com');
        URL::forceScheme('https');
        $this->connectTelegram();
        $this->setSettings(['chat.telegram_staff_chat' => '-100200', 'chat.telegram_groups' => ['-100200' => 'YourHost team']]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        app(TicketDesk::class)->open(Client::factory()->create(), TicketDepartment::query()->firstOrFail(), 'Server down', 'Help');

        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '-100200' && ($request['reply_markup']['inline_keyboard'][0][0]['url'] ?? '') !== '');
    }

    public function test_the_owner_chooses_which_messages_go_where(): void
    {
        $this->signInAdmin();

        $this->put(route('admin.settings.chat.messages'), [
            'events' => ['invoice.created' => ['whatsapp' => '1'], 'ticket.reply' => ['telegram' => '1', 'whatsapp' => '1']],
            'whatsapp_tickets' => '0',
        ])->assertSessionHasNoErrors();

        $events = setting('chat.events');
        $this->assertSame(['telegram' => false, 'whatsapp' => true], $events['invoice.created']);
        $this->assertSame(['telegram' => false, 'whatsapp' => false], $events['domain.expiring']);
        $this->assertFalse(setting('chat.whatsapp_tickets'));
    }

    public function test_a_client_disconnects_a_chat_from_the_account_page(): void
    {
        $client = Client::factory()->create();
        $link = ChatLink::create(['client_id' => $client->id, 'channel' => 'telegram', 'external_id' => '555']);
        $other = ChatLink::create(['client_id' => Client::factory()->create()->id, 'channel' => 'telegram', 'external_id' => '777']);

        $this->actingAs($client, 'web')->delete(route('client.account.chat.destroy', $other))->assertNotFound();
        $this->actingAs($client, 'web')->delete(route('client.account.chat.destroy', $link))->assertRedirect();

        $this->assertModelMissing($link);
        $this->assertModelExists($other);
    }

    private function connectTelegram(): void
    {
        $this->setSettings(['chat.telegram_token' => self::BOT, 'chat.telegram_bot' => 'YourHostBot', 'chat.telegram_secret' => self::SECRET]);
    }

    /**
     * @param  array<string, array<string, string>>  $templates
     */
    private function connectWhatsApp(array $templates = []): void
    {
        $this->setSettings([
            'chat.whatsapp_token' => 'EAABtoken',
            'chat.whatsapp_waba' => '102290129340398',
            'chat.whatsapp_phone_id' => '1001',
            'chat.whatsapp_number' => '+964 750 123 4567',
            'chat.whatsapp_webhook_key' => self::WHATSAPP_KEY,
            'chat.whatsapp_via' => 'qr',
            'chat.whatsapp_templates' => $templates,
        ]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function telegramUpdate(array $update): TestResponse
    {
        return $this->postJson(route('webhooks.telegram'), $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET]);
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsappMessage(string $from, string $text, ?string $name = null): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => '1001'],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name ?? 'Raz']]],
            'messages' => [['from' => $from, 'id' => 'wamid.in', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }
}
