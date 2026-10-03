<?php

namespace Tests\Feature;

use App\Ai\Redactor;
use App\Enums\TicketPriority;
use App\Jobs\TranslateTicketReply;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\AiUsage;
use App\Models\Client;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeClaude;
use Tests\TestCase;

class AiHelpTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-ant-api03-test-key';

    public function test_the_owner_saves_the_key_encrypted_and_picks_a_model_and_limit(): void
    {
        $this->signInAdmin();

        $this->put(route('admin.settings.ai.update'), ['key' => 'not-a-key', 'model' => 'claude-sonnet-5-5', 'monthly_limit' => '15', 'staff_language' => 'en'])
            ->assertSessionHasErrors('key');

        $this->put(route('admin.settings.ai.update'), [
            'key' => self::KEY,
            'model' => 'claude-sonnet-5-5',
            'monthly_limit' => '15.50',
            'staff_language' => 'de',
            'drafts' => '1',
            'translate' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::KEY, setting('ai.key'));
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('settings')->where('key', 'ai.key')->value('value'));
        $this->assertSame('claude-sonnet-5-5', setting('ai.model'));
        $this->assertSame(1550, setting('ai.monthly_limit'));
        $this->assertSame('de', setting('ai.staff_language'));
        $this->assertFalse(setting('ai.summaries'));

        // An empty key field keeps the saved key.
        $this->put(route('admin.settings.ai.update'), ['model' => 'claude-haiku-4-5', 'monthly_limit' => '20', 'staff_language' => 'en'])->assertSessionHasNoErrors();
        $this->assertSame(self::KEY, setting('ai.key'));

        $this->get(route('admin.settings.ai.edit'))->assertOk()->assertSee('Claude Opus 5.5')->assertDontSee(self::KEY);
    }

    public function test_the_test_button_sends_one_tiny_request_with_the_saved_key(): void
    {
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY]);
        $claude = FakeClaude::install()->answer('OK', 20, 2);

        $this->post(route('admin.settings.ai.test'))->assertSessionHas('status', 'AI help works. Claude Haiku 4.5: OK');

        $this->assertSame([self::KEY], $claude->keys);
        $this->assertSame('claude-haiku-4-5', $claude->requests[0]['model']);
        $this->assertSame(1, AiUsage::query()->count());
    }

    public function test_a_draft_uses_the_ticket_without_private_details_and_logs_what_it_cost(): void
    {
        $admin = $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $this->setSettings(['ai.key' => self::KEY]);
        $ticket = $this->ticket('My password: Hunter2x! and my email raz@example.com. Call me on +964 750 123 4567. The site is down since 2026-09-29.');
        $claude = FakeClaude::install()->answer("Hello Raz,\n\nWe are looking at it now.\n\nMer Las", 1200, 300);

        $this->postJson(route('admin.tickets.ai.draft', $ticket), ['instruction' => 'Offer to restore the backup'])
            ->assertOk()
            ->assertJson(['draft' => "Hello Raz,\n\nWe are looking at it now.\n\nMer Las"]);

        $sent = $claude->lastSentText();
        $this->assertStringContainsString('Offer to restore the backup', $sent);
        $this->assertStringContainsString('2026-09-29', $sent);
        $this->assertStringContainsString('Write the next reply from Mer Las', $sent);
        $this->assertStringNotContainsString('Hunter2x', $sent);
        $this->assertStringNotContainsString('raz@example.com', $sent);
        $this->assertStringNotContainsString('750 123 4567', $sent);
        $this->assertStringNotContainsString($ticket->client->email, $sent);

        $usage = AiUsage::query()->sole();
        $this->assertSame('drafts', $usage->feature);
        $this->assertSame($admin->id, $usage->admin_id);
        // Haiku 4.5: $1 per million tokens in, $5 out.
        $this->assertSame(1200 + 300 * 5, $usage->cost_micros);
    }

    public function test_newer_models_think_first_and_can_be_rescued_by_another_model(): void
    {
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY, 'ai.model' => 'claude-sonnet-5-5']);
        $claude = FakeClaude::install()->answer('Hello Raz,', model: 'claude-sonnet-5-5');

        $this->postJson(route('admin.tickets.ai.draft', $this->ticket('It does not work.')))->assertOk();

        $request = $claude->requests[0];
        $this->assertSame('claude-sonnet-5-5', $request['model']);
        $this->assertSame('medium', $request['output_config']['effort']);
        $this->assertSame('default', $request['fallbacks']);
        $this->assertContains('server-side-fallback-2026-07-01', $request['_headers']['anthropic-beta'] ?? []);
    }

    public function test_ai_help_pauses_when_the_monthly_limit_is_reached(): void
    {
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY, 'ai.monthly_limit' => 100]);
        AiUsage::create(['feature' => 'drafts', 'model' => 'claude-haiku-4-5', 'cost_micros' => 1_000_000]);
        $claude = FakeClaude::install();

        $this->postJson(route('admin.tickets.ai.draft', $this->ticket('Help')))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'The monthly AI limit is reached'));

        $this->assertSame([], $claude->requests);
    }

    public function test_staff_are_emailed_once_when_spending_passes_80_percent(): void
    {
        Mail::fake();
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY, 'ai.monthly_limit' => 100, 'company.email' => 'team@example.test']);
        AiUsage::create(['feature' => 'drafts', 'model' => 'claude-haiku-4-5', 'cost_micros' => 790_000]);
        FakeClaude::install()->answer('Hello', 5000, 2000)->answer('Hello', 100, 10);
        $ticket = $this->ticket('Help');

        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertOk();
        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertOk();

        Mail::assertSent(TemplatedMessage::class, 1);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('team@example.test') && $mail->subjectLine === 'AI help has used 80% of its monthly limit');
    }

    public function test_api_errors_and_refusals_become_plain_messages(): void
    {
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY]);
        FakeClaude::install()
            ->error(401, 'authentication_error', 'invalid x-api-key')
            ->error(400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API.')
            ->answer('', stopReason: 'refusal');
        $ticket = $this->ticket('Help');

        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertStatus(422)->assertJsonPath('message', 'Anthropic did not accept the AI key. Check it in Settings → AI.');
        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertStatus(422)->assertJsonPath('message', 'The AI could not answer: Your credit balance is too low to access the Anthropic API.');
        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertStatus(422)->assertJsonPath('message', 'The AI declined to answer this one. Please write it yourself.');
    }

    public function test_the_summary_suggests_a_department_and_priority_and_is_kept_on_the_ticket(): void
    {
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY]);
        TicketDepartment::factory()->create(['name' => 'Technical']);
        $ticket = $this->ticket('My site shows a database error after moving.');
        FakeClaude::install()->answer(['summary' => 'Raz moved a site and it cannot reach its database.', 'department' => 'Technical', 'priority' => 'high']);

        $this->postJson(route('admin.tickets.ai.summary', $ticket))
            ->assertOk()
            ->assertJson(['summary' => 'Raz moved a site and it cannot reach its database.', 'department' => 'Technical', 'priority' => 'high', 'priority_label' => 'High']);

        $this->assertSame('Technical', $ticket->fresh()->ai_summary['department']);
        $this->get(route('admin.tickets.show', $ticket))->assertOk()->assertSee('Raz moved a site and it cannot reach its database.');
    }

    public function test_a_client_message_in_another_language_is_translated_for_staff(): void
    {
        $this->setSettings(['ai.key' => self::KEY]);
        $client = Client::factory()->create(['first_name' => 'Raz', 'language' => 'ar']);
        $claude = FakeClaude::install()->answer(['language' => 'ar', 'translation' => 'My website shows an error after moving.']);

        $ticket = app(TicketDesk::class)->open($client, TicketDepartment::query()->firstOrFail(), 'خطأ', 'موقعي يظهر خطأ بعد النقل');

        $reply = $ticket->replies()->sole();
        $this->assertSame('ar', $reply->language);
        $this->assertSame('My website shows an error after moving.', $reply->translation);
        $this->assertCount(1, $claude->requests);

        $this->signInAdmin();
        $this->get(route('admin.tickets.show', $ticket))->assertOk()
            ->assertSee('Translated from Arabic')
            ->assertSee('My website shows an error after moving.')
            ->assertSee('Send it in Arabic');
    }

    public function test_one_client_cannot_cause_unlimited_automatic_translations(): void
    {
        Mail::fake();
        $this->setSettings(['ai.key' => self::KEY]);
        $client = Client::factory()->create(['first_name' => 'Raz', 'language' => 'ar']);
        $claude = FakeClaude::install();

        foreach (range(1, 40) as $ignored) {
            $claude->answer(['language' => 'ar', 'translation' => 'Hello again.']);
        }

        $desk = app(TicketDesk::class);
        $ticket = $desk->open($client, TicketDepartment::query()->firstOrFail(), 'سؤال', 'مرحبا');

        foreach (range(1, 39) as $ignored) {
            $desk->replyAsClient($ticket, $client, 'مرحبا مرة أخرى');
        }

        $this->assertCount(30, $claude->requests, 'At most 30 automatic translations an hour for one client.');
        $this->assertNull($ticket->replies()->reorder()->latest('id')->first()->language, 'Staff can still press Translate.');
    }

    public function test_long_messages_and_the_last_fifth_of_the_limit_are_not_translated_automatically(): void
    {
        Mail::fake();
        $this->setSettings(['ai.key' => self::KEY, 'ai.monthly_limit' => 100]);
        $client = Client::factory()->create(['first_name' => 'Raz', 'language' => 'ar']);
        $claude = FakeClaude::install();
        $department = TicketDepartment::query()->firstOrFail();

        app(TicketDesk::class)->open($client, $department, 'سؤال', str_repeat('مرحبا ', 1000));
        $this->assertSame([], $claude->requests);

        // 85 cents of the 1 dollar limit are used: the rest is kept for what staff ask for.
        AiUsage::create(['feature' => 'drafts', 'model' => 'claude-haiku-4-5', 'cost_micros' => 850_000]);
        app(TicketDesk::class)->open($client, $department, 'سؤال', 'مرحبا');
        $this->assertSame([], $claude->requests);
    }

    public function test_automatic_translations_wait_behind_other_background_work(): void
    {
        Queue::fake();
        $this->setSettings(['ai.key' => self::KEY]);
        $client = Client::factory()->create(['first_name' => 'Raz', 'language' => 'ar']);

        app(TicketDesk::class)->open($client, TicketDepartment::query()->firstOrFail(), 'سؤال', 'مرحبا');

        Queue::assertPushedOn(TranslateTicketReply::QUEUE, TranslateTicketReply::class);
    }

    public function test_the_ticket_subject_is_cleaned_before_it_reaches_the_ai(): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $this->setSettings(['ai.key' => self::KEY]);
        $ticket = $this->ticket('It does not work.');
        $ticket->update(['subject' => 'cPanel login raz@example.com password: Hunter2x!']);
        $claude = FakeClaude::install()
            ->answer("Hello Raz,\n\nMer Las")
            ->answer(['summary' => 'Raz cannot sign in to cPanel.', 'department' => 'Support', 'priority' => 'medium']);

        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertOk();
        $draft = $claude->lastSentText();
        $this->postJson(route('admin.tickets.ai.summary', $ticket))->assertOk();
        $summary = $claude->lastSentText();

        foreach ([$draft, $summary] as $sent) {
            $this->assertStringNotContainsString('Hunter2x', $sent);
            $this->assertStringNotContainsString('raz@example.com', $sent);
            $this->assertStringContainsString('password: [password]', $sent);
            $this->assertStringContainsString('[email]', $sent);
        }
    }

    public function test_english_messages_to_an_english_team_are_not_sent_for_translation(): void
    {
        $this->setSettings(['ai.key' => self::KEY]);
        $claude = FakeClaude::install();
        $client = Client::factory()->create(['language' => 'en']);

        app(TicketDesk::class)->open($client, TicketDepartment::query()->firstOrFail(), 'Help', 'My website is slow today.');

        $this->assertSame([], $claude->requests);
    }

    public function test_a_reply_is_sent_in_the_translation_staff_checked(): void
    {
        Mail::fake();
        $admin = $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY]);
        $ticket = $this->ticket('مرحبا', ['language' => 'ar']);
        $ticket->replies()->first()->update(['language' => 'ar']);
        $claude = FakeClaude::install();

        $this->post(route('admin.tickets.reply', $ticket), [
            'message' => "Hello Raz,\r\nPlease check wp-config.php.",
            'status' => 'answered',
            'translate' => '1',
            'translation' => 'مرحبا راز، يرجى التحقق من wp-config.php.',
            'translated_from' => "Hello Raz,\nPlease check wp-config.php.",
        ])->assertRedirect();

        $reply = $ticket->replies()->reorder()->latest('id')->first();
        $this->assertSame('مرحبا راز، يرجى التحقق من wp-config.php.', $reply->message);
        $this->assertSame("Hello Raz,\r\nPlease check wp-config.php.", $reply->original_message);
        $this->assertSame('ar', $reply->language);
        $this->assertSame($admin->id, $reply->author_id);
        $this->assertSame([], $claude->requests);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($ticket->client->email) && str_contains($mail->render(), 'wp-config.php'));
    }

    public function test_a_reply_changed_after_the_preview_is_translated_again_and_private_details_stay_on_the_server(): void
    {
        Mail::fake();
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY]);
        $ticket = $this->ticket('مرحبا', ['language' => 'ar']);
        $claude = FakeClaude::install()->answer('كلمة المرور الجديدة: [password 1]');

        $this->post(route('admin.tickets.reply', $ticket), [
            'message' => 'Your new password: Blue-Horse-42',
            'status' => 'answered',
            'translate' => '1',
            'translation' => 'old translation',
            'translated_from' => 'something else',
        ])->assertRedirect();

        $this->assertStringNotContainsString('Blue-Horse-42', $claude->lastSentText());
        $this->assertSame('كلمة المرور الجديدة: Blue-Horse-42', $ticket->replies()->reorder()->latest('id')->first()->message);
    }

    public function test_write_with_ai_fills_the_product_texts(): void
    {
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY]);
        $product = Product::factory()->create(['name' => 'Starter Hosting']);
        $claude = FakeClaude::install()->answer([
            'description' => "1 website\n10 GB NVMe storage\nFree SSL",
            'seo_title' => 'Starter Hosting',
            'seo_description' => 'Starter Hosting: 1 website, 10 GB NVMe storage and free SSL.',
        ]);

        $this->get(route('admin.products.edit', $product))->assertOk()->assertSee('Write with AI');

        $this->postJson(route('admin.products.ai.write'), ['name' => 'Starter Hosting', 'description' => "1 website\n10 GB", 'product' => $product->id])
            ->assertOk()
            ->assertJson(['description' => "1 website\n10 GB NVMe storage\nFree SSL", 'seo_title' => 'Starter Hosting']);

        $this->assertStringContainsString('Starter Hosting', $claude->lastSentText());
        $this->assertSame('descriptions', AiUsage::query()->sole()->feature);
    }

    public function test_staff_without_the_right_see_no_ai_buttons_and_cannot_use_it(): void
    {
        $this->setSettings(['ai.key' => self::KEY]);
        $this->signInAdmin(Admin::factory()->withPermissions(['support.manage'])->create());
        $ticket = $this->ticket('Help');

        $this->get(route('admin.tickets.show', $ticket))->assertOk()->assertDontSee('Write a draft');
        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertForbidden();
    }

    public function test_switched_off_features_are_refused(): void
    {
        $this->signInAdmin();
        $this->setSettings(['ai.key' => self::KEY, 'ai.drafts' => false]);
        $claude = FakeClaude::install();
        $ticket = $this->ticket('Help');

        $this->get(route('admin.tickets.show', $ticket))->assertOk()->assertDontSee('Write a draft');
        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertStatus(422)->assertJsonPath('message', 'This AI help is switched off in Settings → AI.');
        $this->assertSame([], $claude->requests);
    }

    public function test_the_demo_shows_samples_and_never_calls_anthropic(): void
    {
        config(['nuvabill.demo' => true]);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $claude = FakeClaude::install();
        $ticket = $this->ticket('Help');

        $this->get(route('admin.tickets.show', $ticket))->assertOk()->assertSee('Write a draft');
        $this->postJson(route('admin.tickets.ai.draft', $ticket))->assertOk()->assertJsonPath('draft', fn (string $draft): bool => str_contains($draft, 'Mer Las'))->assertJsonStructure(['notice']);
        $this->assertSame([], $claude->requests);
    }

    public function test_private_details_are_masked_and_put_back(): void
    {
        $text = 'Mail raz@example.com or call +964 750 123 4567. Card 4242 4242 4242 4242. Server 185.117.98.91, 2026-09-29, ticket 204133.';
        [$masked, $found] = Redactor::mask($text);

        $this->assertSame('Mail [email 1] or call [phone 2]. Card [card number 3]. Server 185.117.98.91, 2026-09-29, ticket 204133.', $masked);
        $this->assertSame($text, Redactor::restore($masked, $found));
        $this->assertNull(Redactor::restore('Mail [email 1] or call.', $found));
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function ticket(string $message, array $client = []): Ticket
    {
        $client = Client::factory()->create(['first_name' => 'Raz'] + $client);
        $ticket = Ticket::factory()->for($client)->create(['priority' => TicketPriority::Medium]);
        $ticket->replies()->create(['author_type' => 'client', 'author_id' => $client->id, 'message' => $message]);

        return $ticket;
    }
}
