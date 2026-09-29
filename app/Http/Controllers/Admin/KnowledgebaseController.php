<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Seo\Sitemap;
use App\Support\Activity;
use App\Support\ContentLanguages;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff write knowledge base articles in categories, in the default language and, if they like,
 * in each other language.
 */
class KnowledgebaseController extends Controller
{
    /**
     * Addresses the knowledge base itself uses, so no category can take them.
     */
    public const RESERVED_CATEGORY_SLUGS = ['suggest'];

    public function index(): View
    {
        return view('admin.knowledgebase.index', [
            'categories' => KbCategory::query()->ordered()->with(['articles' => fn ($articles) => $articles->ordered()->with('translations')])->get(),
            'languages' => ContentLanguages::others(),
        ]);
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $settings->set('knowledgebase.enabled', $request->boolean('enabled'));
        Sitemap::forget();

        return back()->with('status', __('Saved.'));
    }

    public function createCategory(): View
    {
        return view('admin.knowledgebase.category', ['category' => new KbCategory(['is_visible' => true]), 'locale' => null, 'languages' => ContentLanguages::others()]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $this->validatedCategory($request);
        $category = KbCategory::query()->create($data);
        Activity::log('kb.category_created', "Knowledge base category {$category->name} added");
        Sitemap::forget();

        return redirect()->route('admin.kb.index')->with('status', __('Category added.'));
    }

    public function editCategory(Request $request, KbCategory $category): View
    {
        $category->load('translations');

        return view('admin.knowledgebase.category', [
            'category' => $category,
            'locale' => ContentLanguages::chosen($request->query('lang')),
            'languages' => ContentLanguages::others(),
        ]);
    }

    public function updateCategory(Request $request, KbCategory $category): RedirectResponse
    {
        if ($locale = ContentLanguages::chosen($request->input('locale'))) {
            $data = $request->validate(['title' => ['nullable', 'string', 'max:120'], 'body' => ['nullable', 'string', 'max:500']]);
            $category->saveTranslation($locale, $data['title'] ?? null, $data['body'] ?? null);

            return redirect()->route('admin.kb.categories.edit', [$category, 'lang' => $locale])->with('status', __('Translation saved.'));
        }

        $category->update($this->validatedCategory($request, $category));
        Sitemap::forget();

        return redirect()->route('admin.kb.index')->with('status', __('Category saved.'));
    }

    public function destroyCategory(KbCategory $category): RedirectResponse
    {
        if ($category->articles()->exists()) {
            return back()->with('error', __('Move or delete the articles in this category first.'));
        }

        $category->delete();
        Activity::log('kb.category_deleted', "Knowledge base category {$category->name} deleted");
        Sitemap::forget();

        return redirect()->route('admin.kb.index')->with('status', __('Category deleted.'));
    }

    public function createArticle(Request $request): View|RedirectResponse
    {
        $categories = KbCategory::query()->ordered()->pluck('name', 'id');

        if ($categories->isEmpty()) {
            return redirect()->route('admin.kb.categories.create')->with('status', __('Add a category first. Articles go into categories.'));
        }

        return view('admin.knowledgebase.article', [
            'article' => new KbArticle(['is_published' => true, 'kb_category_id' => (int) $request->query('category') ?: null]),
            'categories' => $categories,
            'locale' => null,
            'languages' => ContentLanguages::others(),
        ]);
    }

    public function storeArticle(Request $request): RedirectResponse
    {
        $article = KbArticle::query()->create($this->validatedArticle($request));
        Activity::log('kb.article_created', "Knowledge base article {$article->title} added");
        Sitemap::forget();

        return redirect()->route('admin.kb.articles.edit', $article)->with('status', __('Article saved.'));
    }

    public function editArticle(Request $request, KbArticle $article): View
    {
        $article->load(['translations', 'category']);

        return view('admin.knowledgebase.article', [
            'article' => $article,
            'categories' => KbCategory::query()->ordered()->pluck('name', 'id'),
            'locale' => ContentLanguages::chosen($request->query('lang')),
            'languages' => ContentLanguages::others(),
        ]);
    }

    public function updateArticle(Request $request, KbArticle $article): RedirectResponse
    {
        if ($locale = ContentLanguages::chosen($request->input('locale'))) {
            $data = $request->validate(['title' => ['nullable', 'string', 'max:190'], 'body' => ['nullable', 'string', 'max:100000']]);
            $article->saveTranslation($locale, $data['title'] ?? null, $data['body'] ?? null);

            return redirect()->route('admin.kb.articles.edit', [$article, 'lang' => $locale])->with('status', __('Translation saved.'));
        }

        $article->update($this->validatedArticle($request, $article));
        Sitemap::forget();

        return redirect()->route('admin.kb.articles.edit', $article)->with('status', __('Article saved.'));
    }

    public function destroyArticle(KbArticle $article): RedirectResponse
    {
        $article->delete();
        Activity::log('kb.article_deleted', "Knowledge base article {$article->title} deleted");
        Sitemap::forget();

        return redirect()->route('admin.kb.index')->with('status', __('Article deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCategory(Request $request, ?KbCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(self::RESERVED_CATEGORY_SLUGS), Rule::unique('kb_categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_visible' => ['boolean'],
        ], ['slug.regex' => __('Use only small letters, numbers and dashes.')]);

        $data['slug'] = filled($data['slug'] ?? null) ? $data['slug'] : ContentLanguages::slug(KbCategory::class, $data['name'], $category?->id, self::RESERVED_CATEGORY_SLUGS);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_visible'] = $request->boolean('is_visible');

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedArticle(Request $request, ?KbArticle $article = null): array
    {
        $data = $request->validate([
            'kb_category_id' => ['required', 'integer', 'exists:kb_categories,id'],
            'title' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('kb_articles', 'slug')->ignore($article?->id)],
            'body' => ['required', 'string', 'max:100000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_published' => ['boolean'],
        ], ['slug.regex' => __('Use only small letters, numbers and dashes.')]);

        $data['slug'] = filled($data['slug'] ?? null) ? $data['slug'] : ContentLanguages::slug(KbArticle::class, $data['title'], $article?->id);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_published'] = $request->boolean('is_published');

        return $data;
    }
}
