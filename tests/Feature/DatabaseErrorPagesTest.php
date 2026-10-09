<?php

namespace Tests\Feature;

use App\Billing\CreditNotes;
use App\Billing\InvoiceManager;
use App\Billing\QuoteManager;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Enums\QuoteStatus;
use App\Marketplace\MarketplaceClient;
use App\Marketplace\PackageInstaller;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quote;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * A database error during an action (a lock that waited too long, say) goes to the error page and
 * the log. Its message names the database server, the database and the query, so it never becomes
 * the message on the page, for staff or for clients. Other refusals still show their own message.
 */
class DatabaseErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_database_error_is_not_shown_on_the_page(): void
    {
        config(['app.debug' => false]);
        Exceptions::fake();
        $error = new QueryException(
            'mysql',
            'select * from `invoices` where `id` = ? for update',
            [7],
            new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'),
            ['driver' => 'mysql', 'host' => 'db-host-marker', 'port' => 3306, 'database' => 'nuvabill-db-marker'],
        );
        $pages = $this->pages($error);

        foreach ($pages as $page => $request) {
            $this->flushSession();
            $response = $request();

            $this->assertSame(500, $response->getStatusCode(), $page);
            $this->assertNull(session('error'), $page);
            $response->assertSessionHasNoErrors();

            foreach (['db-host-marker', 'nuvabill-db-marker', 'for update', 'Lock wait'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $response->getContent(), $page);
            }
        }

        // Staff still find each one in the log, once. In a transaction inside another, Laravel throws
        // its own PDOException with the same message.
        $reported = Exceptions::reported();
        $this->assertCount(count($pages), $reported);

        foreach ($reported as $exception) {
            $this->assertInstanceOf(PDOException::class, $exception);
            $this->assertStringContainsString('db-host-marker', $exception->getMessage());
        }
    }

    public function test_other_refusals_still_show_their_own_message(): void
    {
        $pages = $this->pages(new RuntimeException('Refused for a reason staff and clients may read.'));

        foreach ($pages as $page => $request) {
            $this->flushSession();
            $response = $request();

            $this->assertSame(302, $response->getStatusCode(), $page);

            if (session('error') === null) {
                $response->assertSessionHasErrors(['amount' => 'Refused for a reason staff and clients may read.']);
            } else {
                $this->assertStringContainsString('Refused for a reason staff and clients may read.', session('error'), $page);
            }
        }
    }

    /**
     * Every page that turns a refused action into a message, each failing with the given error.
     *
     * @return array<string, Closure(): TestResponse>
     */
    private function pages(Throwable $error): array
    {
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null]);
        $admin = Admin::factory()->create(['name' => 'Raz']);
        $quote = Quote::factory()->create(['client_id' => $client->id, 'status' => QuoteStatus::Sent]);
        $unpaid = Invoice::factory()->create(['client_id' => $client->id]);
        $paid = Invoice::factory()->create(['client_id' => $client->id, 'status' => InvoiceStatus::Paid, 'total' => 1000, 'subtotal' => 1000, 'amount_paid' => 1000, 'paid_at' => now()]);
        $order = Order::factory()->create(['client_id' => $client->id, 'invoice_id' => Invoice::factory()->create(['client_id' => $client->id])->id]);

        $this->mock(QuoteManager::class, function (MockInterface $mock) use ($error): void {
            $mock->shouldReceive('accept', 'decline', 'send')->andThrow($error);
        });
        $this->mock(Wallet::class, fn (MockInterface $mock) => $mock->shouldReceive('change')->andThrow($error));
        $this->mock(InvoiceManager::class, fn (MockInterface $mock) => $mock->shouldReceive('cancel')->andThrow($error));
        $this->mock(CreditNotes::class, fn (MockInterface $mock) => $mock->shouldReceive('issue')->andThrow($error));
        $this->mock(MarketplaceClient::class, fn (MockInterface $mock) => $mock->shouldReceive('download')->andReturn(['url' => 'https://marketplace.example.test/notes.zip']));
        $this->mock(PackageInstaller::class, fn (MockInterface $mock) => $mock->shouldReceive('install')->andThrow($error));

        $asClient = fn (): static => $this->actingAs($client, 'web');
        $asStaff = fn (): static => $this->actingAs($admin, 'admin');

        return [
            'client accepts a quote' => fn () => $asClient()->post(route('client.quotes.accept', $quote)),
            'client declines a quote' => fn () => $asClient()->post(route('client.quotes.decline', $quote)),
            'staff send a quote' => fn () => $asStaff()->post(route('admin.quotes.send', $quote)),
            'staff change a wallet' => fn () => $asStaff()->post(route('admin.clients.wallet', $client), ['amount' => '5', 'reason' => 'Goodwill']),
            'staff cancel an order' => fn () => $asStaff()->post(route('admin.orders.cancel', $order->fresh())),
            'staff cancel an invoice' => fn () => $asStaff()->post(route('admin.invoices.cancel', $unpaid)),
            'staff refund an invoice' => fn () => $asStaff()->post(route('admin.invoices.refund', $paid)),
            'staff make a credit note' => fn () => $asStaff()->post(route('admin.invoices.credit-notes.store', $paid), ['amount' => '1', 'method' => 'none']),
            'staff install from the marketplace' => fn () => $asStaff()->post(route('admin.marketplace.install', 'notes')),
        ];
    }
}
