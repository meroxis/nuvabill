<?php

namespace Tests\Feature;

use App\Chat\ChatMessages;
use App\Chat\LinkCodes;
use App\Chat\TelegramGroups;
use App\Chat\WhatsApp;
use App\Chat\WhatsAppSetup;
use App\Enums\ClientStatus;
use App\Mail\TemplatedMessage;
use App\Mail\TemplateMailer;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ChatLink;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\TicketDesk;
use Closure;
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

    public function test_chat_messages_use_the_clients_language_only_while_the_host_has_it_switched_on(): void
    {
        Mail::fake();
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $client = Client::factory()->create(['first_name' => 'Raz', 'language' => 'de']);
        ChatLink::create(['client_id' => $client->id, 'channel' => 'telegram', 'external_id' => '555']);
        $ticket = app(TicketDesk::class)->open($client, TicketDepartment::query()->firstOrFail(), 'SSL', 'Not secure warning');

        // German is switched on: answers and alerts are in German.
        $this->setSettings(['locale.enabled' => ['en', 'de'], 'locale.default' => 'en']);
        $this->telegramUpdate(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => '/invoices']]);
        app(TicketDesk::class)->replyAsStaff($ticket, Admin::factory()->create(), 'First answer.');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Sie haben keine unbezahlten Rechnungen.'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Neue Antwort auf Ticket'));

        // The host switches German off: chat follows the same rule as emails and uses the site language.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->setSettings(['locale.enabled' => ['en']]);
        $this->telegramUpdate(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => '/invoices']]);
        app(TicketDesk::class)->replyAsStaff($ticket, Admin::factory()->create(), 'Second answer.');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'You have no unpaid invoices.'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'New reply on ticket'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Second answer.') && str_contains((string) $request['text'], 'Neue Antwort'));
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

    public function test_a_group_is_only_a_choice_for_staff_alerts_after_the_message_from_the_settings_page_was_sent_in_it(): void
    {
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]), 'my.nuvabill.com/*' => Http::response(['ready' => true])]);
        $this->signInAdmin();
        $inGroup = fn (int $id, string $status): array => ['my_chat_member' => ['chat' => ['id' => $id, 'type' => 'supergroup', 'title' => 'YourHost team'], 'new_chat_member' => ['status' => $status]]];

        // Anyone on Telegram can add the bot to a group with any name. That alone lists nothing.
        $this->telegramUpdate($inGroup(-100900, 'member'))->assertOk();
        $this->assertSame([], setting('chat.telegram_groups'));
        $page = $this->get(route('admin.settings.chat.edit'))->assertOk()->assertDontSee('YourHost team');
        $this->put(route('admin.settings.chat.telegram.staff'), ['staff_chat' => '-100900'])->assertSessionHasErrors('staff_chat');
        $this->assertSame('', setting('chat.telegram_staff_chat'));

        // The settings page shows the message to send. Another code does nothing.
        $command = app(TelegramGroups::class)->command();
        $this->assertMatchesRegularExpression('/^\/start@YourHostBot [A-Za-z0-9]{12}$/', $command);
        $page->assertSee($command)->assertSee('https://t.me/YourHostBot?startgroup='.substr($command, -12), false);
        $this->groupMessage(-100900, 'YourHost team', '/start@YourHostBot notTheCode12')->assertOk();
        $this->groupMessage(-100900, 'YourHost team', str_replace('@YourHostBot', '@OtherBot', $command))->assertOk();
        $this->assertSame([], setting('chat.telegram_groups'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));

        // Sent in the team's own group, the group becomes a choice and the bot says so there.
        $this->groupMessage(-100200, 'Staff room', $command)->assertOk();
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage') && $request['chat_id'] === '-100200' && str_contains($request['text'], 'staff alerts'));
        $this->assertTrue(ActivityLog::query()->where('action', 'chat.telegram')->where('description', 'like', '%Staff room%by Mer%')->exists());
        $this->get(route('admin.settings.chat.edit'))->assertSee('Staff room');
        $this->put(route('admin.settings.chat.telegram.staff'), ['staff_chat' => '-100200'])->assertSessionHas('status');
        $this->assertSame('-100200', setting('chat.telegram_staff_chat'));

        // The code worked once, and a new one stops working after an hour.
        $this->groupMessage(-100900, 'YourHost team', $command)->assertOk();
        $next = app(TelegramGroups::class)->command();
        $this->assertNotSame($command, $next);
        $this->travel(61)->minutes();
        $this->groupMessage(-100900, 'YourHost team', $next)->assertOk();
        $this->assertSame(['-100200'], array_map('strval', array_keys(setting('chat.telegram_groups'))));

        // A group the bot left is no longer a choice.
        $this->telegramUpdate($inGroup(-100200, 'left'))->assertOk();
        $this->assertSame([], setting('chat.telegram_groups'));
    }

    public function test_groups_listed_before_staff_confirmed_them_are_dropped_except_the_one_already_chosen(): void
    {
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]), 'my.nuvabill.com/*' => Http::response(['ready' => true])]);
        // As an older version saved them: every group the bot was added to, by name.
        $this->setSettings(['chat.telegram_staff_chat' => '-100200', 'chat.telegram_groups' => ['-100200' => 'Staff room', '-100900' => 'Staff room two', '-100901' => 'Other']]);
        $this->signInAdmin();

        $this->get(route('admin.settings.chat.edit'))->assertOk()->assertSee('Staff room')->assertDontSee('Staff room two')->assertDontSee('Other');
        $this->assertSame(['-100200'], array_map('strval', array_keys(setting('chat.telegram_groups'))));

        // Alerts keep going to the chosen group, and it can be saved again. The others need the message.
        $this->put(route('admin.settings.chat.telegram.staff'), ['staff_chat' => '-100900'])->assertSessionHasErrors('staff_chat');
        $this->put(route('admin.settings.chat.telegram.staff'), ['staff_chat' => '-100200'])->assertSessionHas('status');
        $this->assertSame('-100200', setting('chat.telegram_staff_chat'));

        // Only the newest groups are kept, and never without the chosen one.
        foreach (range(1, TelegramGroups::LIMIT + 2) as $number) {
            $this->groupMessage(-100300 - $number, 'Group '.$number, app(TelegramGroups::class)->command())->assertOk();
        }

        $kept = array_map('strval', array_keys(setting('chat.telegram_groups')));
        $this->assertCount(TelegramGroups::LIMIT, $kept);
        $this->assertContains('-100200', $kept);
        $this->assertNotContains('-100301', $kept);
        $this->assertContains((string) (-100300 - TelegramGroups::LIMIT - 2), $kept);
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

    public function test_one_word_whatsapp_messages_are_not_taken_for_link_codes(): void
    {
        Mail::fake();
        $this->connectWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $client = Client::factory()->create(['first_name' => 'Raz']);
        Invoice::factory()->for($client)->create(['number' => 'INV-1042']);
        ChatLink::create(['client_id' => $client->id, 'channel' => 'whatsapp', 'external_id' => '9647501234567']);
        $sent = fn (string $text): bool => collect(Http::recorded())->contains(fn (array $call): bool => str_contains((string) ($call[0]['text']['body'] ?? ''), $text));

        $this->postJson(route('webhooks.whatsapp', self::WHATSAPP_KEY), $this->whatsappMessage('9647501234567', 'Invoices'))->assertOk();
        $this->postJson(route('webhooks.whatsapp', self::WHATSAPP_KEY), $this->whatsappMessage('9647501234567', 'Received'))->assertOk();

        $this->assertTrue($sent('Your unpaid invoices'));
        $this->assertFalse($sent('This code is old or wrong'));
        $this->assertSame('Received', Ticket::query()->sole()->replies()->sole()->message);

        // A stranger's one-word message is left to the owner too.
        $before = count(Http::recorded());
        $this->postJson(route('webhooks.whatsapp', self::WHATSAPP_KEY), $this->whatsappMessage('9647500000000', 'Services'))->assertOk();
        $this->assertCount($before, Http::recorded());

        // The code from the client area still links a chat.
        $this->postJson(route('webhooks.whatsapp', self::WHATSAPP_KEY), $this->whatsappMessage('9647500000001', 'link '.LinkCodes::for($client)))->assertOk();
        $this->assertTrue(ChatLink::query()->where('external_id', '9647500000001')->exists());
    }

    public function test_a_chat_that_floods_the_bot_is_slowed_down(): void
    {
        Mail::fake();
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        ChatLink::create(['client_id' => Client::factory()->create(['first_name' => 'Raz'])->id, 'channel' => 'telegram', 'external_id' => '555']);
        $message = fn (int $number): TestResponse => $this->telegramUpdate(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => 'Message '.$number]])->assertOk();
        $toClient = fn (): array => collect(Http::recorded())->filter(fn (array $call): bool => str_ends_with($call[0]->url(), '/sendMessage') && $call[0]['chat_id'] === '555')->all();

        foreach (range(1, 12) as $number) {
            $message($number);
        }

        $ticket = Ticket::query()->sole();
        $this->assertSame(10, $ticket->replies()->count());
        $this->assertCount(11, $toClient(), 'Ten answers and one warning.');
        $this->assertCount(1, collect($toClient())->filter(fn (array $call): bool => str_contains($call[0]['text'], 'too fast')));

        $this->travel(61)->seconds();
        $message(13);
        $this->assertSame(11, $ticket->replies()->count());
    }

    public function test_a_closed_client_cannot_use_a_linked_chat(): void
    {
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $client = Client::factory()->create(['first_name' => 'Raz']);
        Invoice::factory()->for($client)->create(['number' => 'INV-1042']);
        $code = LinkCodes::for($client);
        ChatLink::create(['client_id' => $client->id, 'channel' => 'telegram', 'external_id' => '555']);
        $client->forceFill(['status' => ClientStatus::Closed])->save();
        $send = fn (string $chat, string $text): TestResponse => $this->telegramUpdate(['message' => ['chat' => ['id' => (int) $chat, 'type' => 'private'], 'text' => $text]])->assertOk();

        $send('555', '/invoices');
        $send('555', 'Please open my account again');
        $send('777', '/start '.$code);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertFalse(ChatLink::query()->where('external_id', '777')->exists(), 'A code made before the account closed links nothing.');
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Your unpaid invoices'));
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '555' && str_contains((string) $request['text'], 'This account is closed'));

        // Disconnecting still works.
        $send('555', '/stop');
        $this->assertSame(0, ChatLink::query()->count());
    }

    public function test_a_link_code_works_once_and_stays_the_same_between_page_views(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz']);
        $code = LinkCodes::for($client);

        $this->assertSame($code, LinkCodes::for($client), 'The code on the account page stays the same between page views.');
        // What the cache keeps is text on every cache store, Redis included.
        $this->assertIsString(Cache::get('chat-link:'.$code));

        $link = LinkCodes::redeem(strtolower($code), ChatLink::TELEGRAM, '555', 'Raz');

        $this->assertSame($client->id, $link?->client_id);
        $this->assertNull(Cache::get('chat-link:'.$code), 'A code works once.');
        $this->assertNull(LinkCodes::redeem($code, ChatLink::TELEGRAM, '556', 'Raz'));
        $this->assertNotSame($code, LinkCodes::for($client));
    }

    public function test_a_code_shown_before_a_new_password_links_nothing(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'password' => 'old-password-1']);
        $code = LinkCodes::for($client);

        $client->forceFill(['password' => 'new-password-1'])->save();

        $this->assertNull(LinkCodes::redeem($code, ChatLink::TELEGRAM, '555', 'Raz'));
        $this->assertSame(0, ChatLink::query()->count());

        // The account page shows a new code, and that one links.
        $fresh = LinkCodes::for($client);
        $this->assertNotSame($code, $fresh);
        $this->assertNotNull(LinkCodes::redeem($fresh, ChatLink::TELEGRAM, '555', 'Raz'));

        // A code kept the way older versions did (the client number alone) links nothing either.
        Cache::put('chat-link:ABCD2345', (string) $client->id, now()->addMinutes(30));
        $this->assertNull(LinkCodes::redeem('ABCD2345', ChatLink::TELEGRAM, '556', 'Raz'));
    }

    public function test_a_new_password_saved_while_a_chat_is_being_linked_ends_that_link_too(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['first_name' => 'Raz', 'password' => 'old-password-1']);
        $code = LinkCodes::for($client);

        // The new password is saved between the check of the code and the moment the link is written.
        ChatLink::created(fn () => Client::query()->findOrFail($client->id)->forceFill(['password' => 'new-password-1'])->save());

        $this->assertNull(LinkCodes::redeem($code, ChatLink::TELEGRAM, '555', 'Raz'));
        $this->assertSame(0, ChatLink::query()->count());
        Mail::assertNothingSent();
    }

    public function test_linking_a_chat_emails_the_account_owner_and_is_logged(): void
    {
        Mail::fake();
        $this->connectTelegram();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '']);
        $start = fn (int $chat): TestResponse => $this->telegramUpdate(['message' => ['chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['first_name' => 'Las'], 'text' => '/start '.LinkCodes::for($client)]])->assertOk();

        $start(555);

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($client->email)
            && $mail->subjectLine === 'A chat app was connected to your account'
            && str_contains($mail->bodyHtml, 'Hi Raz,')
            && str_contains($mail->bodyHtml, 'A Telegram chat was connected to your')
            && str_contains($mail->bodyHtml, 'Name of the chat: Las')
            && str_contains($mail->bodyHtml, 'If this was not you')
            && str_contains($mail->bodyHtml, '<a href="'.route('client.account.edit').'">'));
        $log = ActivityLog::query()->where('action', 'client.chat_linked')->sole();
        $this->assertSame('Raz connected a Telegram chat (Las)', $log->description);
        $this->assertSame($client->id, $log->client_id);

        // The same chat with a new code of the same account gives nobody new access: no second email.
        $start(555);
        Mail::assertSentCount(1);

        // Another chat does.
        $start(777);
        Mail::assertSentCount(2);
        $this->assertSame(2, ActivityLog::query()->where('action', 'client.chat_linked')->count());
    }

    public function test_the_name_of_the_chat_is_plain_text_in_that_email(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['first_name' => 'Raz']);

        LinkCodes::redeem(LinkCodes::for($client), ChatLink::WHATSAPP, '9647501234567', '[Open](https://other.example.test) <b>Las</b>');

        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->bodyHtml, 'A WhatsApp chat was connected')
            && str_contains($mail->bodyHtml, 'Name of the chat: [Open](https://other.example.test) &lt;b&gt;Las&lt;/b&gt; +9647501234567')
            && ! str_contains($mail->bodyHtml, 'href="https://other.example.test'));
    }

    public function test_a_closed_account_gets_no_email_about_a_chat(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $code = LinkCodes::for($client);
        $client->forceFill(['status' => ClientStatus::Closed])->save();

        $this->assertNull(LinkCodes::redeem($code, ChatLink::TELEGRAM, '555', 'Raz'));

        Mail::assertNothingSent();
        $this->assertSame(0, ActivityLog::query()->where('action', 'client.chat_linked')->count());
    }

    public function test_qr_connect_shows_no_pin_when_meta_did_not_register_the_number(): void
    {
        $this->connectByQr(fn (Request $request) => Http::response(['error' => ['message' => 'Two step verification PIN Mismatch', 'code' => 133005]], 400));

        $status = (string) session('status');
        $this->assertStringContainsString('WhatsApp is connected: +964 750 123 4567.', $status);
        $this->assertStringContainsString('Meta did not register this number yet', $status);
        $this->assertStringNotContainsString('two-step PIN', $status);
        $this->assertSame('1001', setting('chat.whatsapp_phone_id'), 'The connection is kept.');
        $this->assertTrue(ActivityLog::query()->where('action', 'chat.failed')->exists());
    }

    public function test_qr_connect_shows_the_pin_meta_registered_the_number_with(): void
    {
        $pin = null;
        $this->connectByQr(function (Request $request) use (&$pin) {
            $pin = $request['pin'];

            return Http::response(['success' => true]);
        });

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $pin);
        $this->assertStringContainsString('Its two-step PIN is '.$pin.'.', (string) session('status'));
    }

    /**
     * Connect a new WhatsApp number with Meta's signup page; $register answers the register call.
     */
    private function connectByQr(Closure $register): void
    {
        URL::forceRootUrl('https://billing.example.com');
        URL::forceScheme('https');
        $this->signInAdmin();
        Http::fake(['my.nuvabill.com/connect/whatsapp/status' => Http::response(['ready' => true])]);
        $this->get(route('admin.settings.chat.edit'))->assertOk();
        Http::fake(['graph.facebook.com/*' => fn (Request $request) => match (true) {
            str_contains($request->url(), '/phone_numbers') => Http::response(['data' => [['id' => '1001', 'display_phone_number' => '+964 750 123 4567', 'verified_name' => 'YourHost']]]),
            str_ends_with($request->url(), '1001/register') => $register($request),
            default => Http::response(['data' => []]),
        }]);

        $this->postJson(route('admin.settings.chat.whatsapp.connect'), ['state' => session('chat.whatsapp_state'), 'token' => 'EAABtoken', 'waba_id' => '102290129340398'])->assertOk();
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
     * A message someone sent in a Telegram group the bot is in.
     */
    private function groupMessage(int $chatId, string $title, string $text): TestResponse
    {
        return $this->telegramUpdate(['message' => ['chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => $title], 'from' => ['id' => 7, 'first_name' => 'Mer'], 'text' => $text]]);
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
