<?php

namespace App\Http\Controllers;

use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Seo\Seo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The knowledge base: help articles in categories, with search. Open to everyone, so clients can
 * find answers before they open a ticket and search engines can list the articles.
 */
class KnowledgebaseController extends Controller
{
    public function index(Request $request, Seo $seo): View
    {
        $this->ensureEnabled();
        $query = trim((string) $request->query('q', ''));

        if ($query !== '') {
            $seo->hide();

            return view('theme::knowledgebase.search', [
                'query' => mb_substr($query, 0, 200),
                'articles' => KbArticle::search($query),
            ]);
        }

        $seo->setDescription(__('Answers to common questions about our services, billing and your account.'));
        $categories = KbCategory::query()->visible()->ordered()->withTranslation()
            ->withCount(['articles' => fn ($articles) => $articles->where('is_published', true)])
            ->get()
            ->filter(fn (KbCategory $category): bool => $category->articles_count > 0);

        return view('theme::knowledgebase.index', [
            'categories' => $categories,
            'popular' => KbArticle::query()->public()->withTranslation()->with('category')
                ->orderByDesc('helpful_yes')->ordered()->limit(6)->get(),
        ]);
    }

    public function category(string $category, Seo $seo): View
    {
        $this->ensureEnabled();
        $category = KbCategory::query()->visible()->withTranslation()->where('slug', $category)->firstOrFail();
        $seo->setDescription($category->localized('body') ?: null);

        return view('theme::knowledgebase.category', [
            'category' => $category,
            'articles' => $category->articles()->where('is_published', true)->withTranslation()->ordered()->get(),
        ]);
    }

    public function article(Request $request, string $category, string $article, Seo $seo): View
    {
        $this->ensureEnabled();
        $article = $this->find($category, $article);
        $seo->setDescription($article->excerpt())->setType('article');

        return view('theme::knowledgebase.article', [
            'article' => $article,
            'category' => $article->category,
            'voted' => in_array($article->id, (array) $request->session()->get('kb_voted', []), true),
            'related' => $article->category->articles()->where('is_published', true)->whereKeyNot($article->id)
                ->withTranslation()->ordered()->limit(5)->get(),
        ]);
    }

    /**
     * "Was this article helpful?" Once per visitor session.
     */
    public function vote(Request $request, string $category, string $article): RedirectResponse
    {
        $this->ensureEnabled();
        $article = $this->find($category, $article);
        $helpful = $request->validate(['helpful' => ['required', 'boolean']])['helpful'];
        $voted = (array) $request->session()->get('kb_voted', []);

        if (! in_array($article->id, $voted, true)) {
            $article->increment($helpful ? 'helpful_yes' : 'helpful_no');
            $request->session()->put('kb_voted', array_slice([...$voted, $article->id], -200));
        }

        return redirect()->to($article->url().'#helpful')->with('kb_thanks', true);
    }

    /**
     * Articles that may answer a ticket, while the client types its subject.
     */
    public function suggest(Request $request): JsonResponse
    {
        if (! setting('knowledgebase.enabled')) {
            return response()->json(['articles' => []]);
        }

        $query = trim((string) $request->query('q', ''));

        return response()->json([
            'articles' => mb_strlen($query) < 3 ? [] : KbArticle::search($query, 5)->map(fn (KbArticle $article): array => [
                'title' => $article->localized('title'),
                'url' => $article->url(),
                'excerpt' => $article->excerpt(110),
            ])->all(),
        ]);
    }

    private function find(string $category, string $article): KbArticle
    {
        $article = KbArticle::query()->public()->withTranslation()->with(['category' => fn ($query) => $query->withTranslation()])
            ->where('slug', $article)->firstOrFail();

        abort_unless($article->category->slug === $category, 404);

        return $article;
    }

    private function ensureEnabled(): void
    {
        abort_unless(setting('knowledgebase.enabled'), 404);
    }
}
