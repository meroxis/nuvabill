<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Announcement;
use App\Models\Client;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Announcements: news on a public page, in an RSS feed and on the client dashboard.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
    }

    public function test_staff_post_news_that_visitors_and_clients_see(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['content.manage'])->create(), 'admin')
            ->post(route('admin.announcements.store'), ['title' => 'New VPS plans', 'body' => 'Faster **NVMe** disks.', 'is_published' => '1'])
            ->assertRedirect();
        $announcement = Announcement::query()->where('slug', 'new-vps-plans')->firstOrFail();
        $this->assertNotNull($announcement->published_at);

        $this->get(route('announcements.index'))->assertOk()->assertSee('New VPS plans');
        $this->get(route('announcements.show', 'new-vps-plans'))->assertOk()->assertSee('<strong>NVMe</strong>', false);
        $this->get(route('announcements.feed'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->assertSee('<title>New VPS plans</title>', false);

        $this->actingAs(Client::factory()->create(), 'web')
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertSee('New VPS plans');
    }

    public function test_drafts_and_future_news_stay_hidden_until_their_date(): void
    {
        $draft = Announcement::factory()->create(['is_published' => false, 'title' => 'Draft news']);
        $later = Announcement::factory()->scheduled()->create(['title' => 'Next week news']);

        $this->get(route('announcements.index'))->assertOk()->assertDontSee('Draft news')->assertDontSee('Next week news');
        $this->get(route('announcements.show', $draft->slug))->assertNotFound();
        $this->get(route('announcements.show', $later->slug))->assertNotFound();

        $this->travel(8)->days();
        $this->get(route('announcements.show', $later->slug))->assertOk();
    }

    public function test_the_feed_address_cannot_be_taken_by_an_announcement(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['content.manage'])->create(), 'admin')
            ->post(route('admin.announcements.store'), ['title' => 'Feed', 'body' => 'Text', 'is_published' => '1'])
            ->assertRedirect();

        $this->assertSame('feed-2', Announcement::query()->value('slug'));
    }
}
