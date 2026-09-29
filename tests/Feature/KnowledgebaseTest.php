<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The knowledge base: staff write articles in categories and languages, visitors read and search
 * them, and new tickets suggest matching articles.
 */
class KnowledgebaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
    }

    public function test_staff_write_an_article_and_visitors_read_it(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['content.manage'])->create(), 'admin');

        $this->post(route('admin.kb.categories.store'), ['name' => 'Email', 'is_visible' => '1'])->assertRedirect(route('admin.kb.index'));
        $category = KbCategory::query()->where('slug', 'email')->firstOrFail();

        $this->post(route('admin.kb.articles.store'), [
            'kb_category_id' => $category->id,
            'title' => 'How do I reset my email password?',
            'body' => "Open **Email accounts**.\n\n<script>alert(1)</script>",
            'is_published' => '1',
        ])->assertRedirect();
        $article = KbArticle::query()->where('slug', 'how-do-i-reset-my-email-password')->firstOrFail();

        $this->get(route('kb.index'))->assertOk()->assertSee('Email')->assertSee('How do I reset my email password?');
        $this->get(route('kb.category', 'email'))->assertOk()->assertSee('How do I reset my email password?');
        $this->get(route('kb.article', ['email', $article->slug]))
            ->assertOk()
            ->assertSee('<strong>Email accounts</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<meta name="description" content="Open Email accounts.', false);

        // The article belongs to one category only.
        KbCategory::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
        $this->get(route('kb.article', ['billing', $article->slug]))->assertNotFound();
    }

    public function test_drafts_hidden_categories_and_a_switched_off_knowledge_base_stay_private(): void
    {
        $draft = KbArticle::factory()->draft()->create(['title' => 'Draft about backups']);
        $hidden = KbArticle::factory()->for(KbCategory::factory()->hidden(), 'category')->create(['title' => 'Hidden answer']);

        $this->get(route('kb.article', [$draft->category->slug, $draft->slug]))->assertNotFound();
        $this->get(route('kb.article', [$hidden->category->slug, $hidden->slug]))->assertNotFound();
        $this->get(route('kb.index', ['q' => 'backups']))->assertOk()->assertDontSee('Draft about backups');

        $visible = KbArticle::factory()->create();
        app(Settings::class)->set('knowledgebase.enabled', false);

        $this->get(route('kb.index'))->assertNotFound();
        $this->get(route('kb.article', [$visible->category->slug, $visible->slug]))->assertNotFound();
    }

    public function test_search_finds_words_in_titles_first_and_in_the_visitors_language(): void
    {
        $category = KbCategory::factory()->create();
        KbArticle::factory()->for($category, 'category')->create(['title' => 'Add a new domain', 'body' => 'Point the nameservers to us.']);
        $best = KbArticle::factory()->for($category, 'category')->create(['title' => 'Change your nameservers', 'body' => 'Open the domain.']);
        $best->saveTranslation('de', 'Nameserver ändern', 'Öffnen Sie die Domain und ändern Sie die Nameserver.');

        $results = KbArticle::search('nameservers');
        $this->assertSame([$best->id], $results->take(1)->pluck('id')->all());
        $this->assertCount(2, $results);

        $this->getJson(route('kb.suggest', ['q' => 'nameservers']))
            ->assertOk()
            ->assertJsonPath('articles.0.title', 'Change your nameservers')
            ->assertJsonPath('articles.0.url', $best->url());

        app(Settings::class)->set('locale.enabled', ['en', 'de']);
        $this->withSession(['locale' => 'de'])->get(route('kb.index', ['q' => 'ändern']))
            ->assertOk()
            ->assertSee('Nameserver ändern');
    }

    public function test_translations_show_in_the_visitors_language_and_fall_back_to_the_main_text(): void
    {
        app(Settings::class)->set('locale.enabled', ['en', 'ar', 'de']);
        $admin = Admin::factory()->withPermissions(['content.manage'])->create();
        $article = KbArticle::factory()->create(['title' => 'Reset your password', 'body' => 'Click **Forgot password**.']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.kb.articles.edit', [$article, 'lang' => 'ar']))
            ->assertOk()
            ->assertSee('dir="rtl"', false);

        $this->put(route('admin.kb.articles.update', $article), ['locale' => 'ar', 'title' => 'إعادة تعيين كلمة المرور', 'body' => ''])
            ->assertRedirect(route('admin.kb.articles.edit', [$article, 'lang' => 'ar']));

        $url = route('kb.article', [$article->category->slug, $article->slug]);
        $this->withSession(['locale' => 'ar'])->get($url)->assertOk()->assertSee('إعادة تعيين كلمة المرور')->assertSee('<strong>Forgot password</strong>', false);
        $this->withSession(['locale' => 'de'])->get($url)->assertOk()->assertSee('Reset your password');

        // Both fields empty removes the translation.
        $this->put(route('admin.kb.articles.update', $article), ['locale' => 'ar', 'title' => '', 'body' => '']);
        $this->assertSame(0, $article->translations()->count());
    }

    public function test_visitors_rate_an_article_once(): void
    {
        $article = KbArticle::factory()->create();
        $vote = route('kb.vote', [$article->category->slug, $article->slug]);

        $this->post($vote, ['helpful' => '1'])->assertRedirect();
        $this->post($vote, ['helpful' => '0'])->assertRedirect();

        $this->assertSame(1, $article->fresh()->helpful_yes);
        $this->assertSame(0, $article->fresh()->helpful_no);
    }

    public function test_staff_without_the_right_cannot_write_articles_and_used_categories_are_kept(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['support.manage'])->create(), 'admin')
            ->get(route('admin.kb.index'))
            ->assertForbidden();

        $article = KbArticle::factory()->create();
        $this->actingAs(Admin::factory()->withPermissions(['content.manage'])->create(), 'admin')
            ->delete(route('admin.kb.categories.destroy', $article->category))
            ->assertSessionHas('error');
        $this->assertModelExists($article->category);

        $this->post(route('admin.kb.categories.store'), ['name' => 'Suggest', 'slug' => 'suggest'])->assertSessionHasErrors('slug');
    }

    public function test_the_new_ticket_form_and_the_menus_link_to_the_knowledge_base(): void
    {
        $this->get(route('store.index'))->assertOk()->assertDontSee(route('kb.index'));

        KbArticle::factory()->create();

        $this->get(route('store.index'))->assertOk()->assertSee(route('kb.index'));
        $this->actingAs(Client::factory()->create(), 'web')
            ->get(route('client.tickets.create'))
            ->assertOk()
            ->assertSee('These articles may answer your question:');
    }

    public function test_the_sitemap_lists_published_articles(): void
    {
        $article = KbArticle::factory()->create();
        KbArticle::factory()->draft()->create(['slug' => 'secret-draft']);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee('/knowledgebase/'.$article->category->slug.'/'.$article->slug, false)
            ->assertDontSee('secret-draft', false);
    }
}
