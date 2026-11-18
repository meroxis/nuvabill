<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Seo\Sitemap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The knowledge base and announcements while they have nothing to read: search engines do not
 * list the empty pages, but still follow their links (0.7.4).
 */
class EmptyHelpPagesTest extends TestCase
{
    use RefreshDatabase;

    private const HIDDEN = '<meta name="robots" content="noindex, follow">';

    /**
     * Staff saved new content: on a real site the next page view is a new request, with a fresh
     * search engine state and a sitemap built again.
     */
    private function nextVisit(): void
    {
        $this->app->forgetScopedInstances();
        Sitemap::forget();
    }

    public function test_an_empty_knowledge_base_is_not_offered_to_search_engines(): void
    {
        // Drafts and articles in hidden categories do not count.
        KbArticle::factory()->draft()->create();
        KbArticle::factory()->for(KbCategory::factory()->hidden(), 'category')->create();

        $this->get(route('kb.index'))->assertOk()->assertSee(self::HIDDEN, false);
        $this->get(route('seo.sitemap'))->assertOk()->assertDontSee('<loc>'.url('/').'/knowledgebase</loc>', false);

        KbArticle::factory()->create();
        $this->nextVisit();

        $this->get(route('kb.index'))->assertOk()->assertDontSee('noindex', false);
        $this->get(route('seo.sitemap'))->assertOk()->assertSee('<loc>'.url('/').'/knowledgebase</loc>', false);
    }

    public function test_an_empty_category_is_not_offered_to_search_engines(): void
    {
        $category = KbCategory::factory()->create();
        KbArticle::factory()->draft()->for($category, 'category')->create();

        $this->get(route('kb.category', $category->slug))->assertOk()->assertSee(self::HIDDEN, false);

        KbArticle::factory()->for($category, 'category')->create();
        $this->nextVisit();

        $this->get(route('kb.category', $category->slug))->assertOk()->assertDontSee('noindex', false);
    }

    public function test_announcements_without_news_are_not_offered_to_search_engines(): void
    {
        // Drafts and news planned for later do not count.
        Announcement::factory()->scheduled()->create();
        Announcement::factory()->create(['is_published' => false]);

        $this->get(route('announcements.index'))->assertOk()->assertSee(self::HIDDEN, false);
        $this->get(route('seo.sitemap'))->assertOk()->assertDontSee('<loc>'.url('/').'/announcements</loc>', false);

        Announcement::factory()->create();
        $this->nextVisit();

        $this->get(route('announcements.index'))->assertOk()->assertDontSee('noindex', false);
        $this->get(route('seo.sitemap'))->assertOk()->assertSee('<loc>'.url('/').'/announcements</loc>', false);
    }

    public function test_a_search_in_the_knowledge_base_stays_fully_hidden(): void
    {
        $this->get(route('kb.index', ['q' => 'backups']))->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }
}
