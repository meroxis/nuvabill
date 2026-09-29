<?php

namespace Tests\Feature;

use App\Mail\TemplatedMessage;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Add-ons such as Crystal Mail wrap TemplateMailer in their own subclass. The public methods they
 * override keep their signatures, or PHP refuses to load the add-on and every page that sends
 * email breaks (it happened in 0.6.5–0.6.7).
 */
class MailerExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_add_on_that_wraps_the_mailer_keeps_working_and_keeps_the_clients_language(): void
    {
        $this->seed(DefaultDataSeeder::class);
        Mail::fake();
        app(Settings::class)->set('locale.enabled', ['en', 'fr']);
        app(Settings::class)->set('social.google', ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'secret']);

        // The same shape as Crystal Mail 1.1.0's DecoratedTemplateMailer.
        $this->app->extend(TemplateMailer::class, fn (TemplateMailer $inner): TemplateMailer => new class($inner) extends TemplateMailer
        {
            public function __construct(private TemplateMailer $inner) {}

            public function send(string $key, Client $client, array $context = []): bool
            {
                return parent::send($key, $client, $context);
            }

            public function sendToStaff(string $key, array $context = []): bool
            {
                return parent::sendToStaff($key, $context);
            }

            public function sendTo(string $key, string $email, string $name, array $context = []): bool
            {
                return $this->inner->sendTo($key, $email, $name, $context);
            }
        });

        $client = Client::factory()->create(['language' => 'fr']);
        $this->assertTrue(app(TemplateMailer::class)->send('client.welcome', $client));
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_starts_with($mail->subjectLine, 'Bienvenue'));

        // Pages that need the mailer, such as the return from Google, still load.
        $this->get(route('client.social.callback', ['provider' => 'google', 'state' => 'forged', 'code' => 'x']))
            ->assertRedirect(route('client.login'));
    }
}
