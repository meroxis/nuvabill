<x-layouts.admin :title="__('Knowledge base')">
    <div class="page-head">
        <div>
            <h1>{{ __('Knowledge base') }}</h1>
            <p>{{ __('Help articles clients can read before they open a ticket. New tickets suggest matching articles.') }}</p>
        </div>
        <div class="form-actions">
            <a class="btn" href="{{ route('admin.kb.categories.create') }}"><x-icon name="plus" />{{ __('New category') }}</a>
            @if ($categories->isNotEmpty())
                <a class="btn btn-primary" href="{{ route('admin.kb.articles.create') }}"><x-icon name="plus" />{{ __('New article') }}</a>
            @endif
        </div>
    </div>

    @include('admin.support.nav')

    <div class="grid-2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start">
        <div style="display:grid;gap:14px">
            @forelse ($categories as $category)
                <section class="card card-flush">
                    <div class="card-header" style="padding-bottom:.85rem;border-bottom:1px solid var(--nb-line);margin:0">
                        <div>
                            <h2>{{ $category->name }} @unless ($category->is_visible)<x-pill>{{ __('Hidden') }}</x-pill>@endunless</h2>
                            <span class="faint" style="font-size:.8rem">/knowledgebase/{{ $category->slug }}</span>
                        </div>
                        <div class="form-actions">
                            <a class="btn btn-sm" href="{{ route('admin.kb.categories.edit', $category) }}">{{ __('Edit category') }}</a>
                            <a class="btn btn-sm" href="{{ route('admin.kb.articles.create', ['category' => $category->id]) }}"><x-icon name="plus" />{{ __('Article') }}</a>
                        </div>
                    </div>
                    @if ($category->articles->isEmpty())
                        <div class="empty">{{ __('No articles in this category yet.') }}</div>
                    @else
                        <div class="table-wrap"><table class="table">
                            <thead><tr><th>{{ __('Article') }}</th>@if ($languages !== [])<th>{{ __('Languages') }}</th>@endif<th class="end">{{ __('Helpful') }}</th><th>{{ __('Status') }}</th></tr></thead>
                            <tbody>
                            @foreach ($category->articles as $article)
                                <tr>
                                    <td><a class="row-link" href="{{ route('admin.kb.articles.edit', $article) }}">{{ $article->title }}</a><div class="faint mono" style="font-size:.78rem">{{ $article->slug }}</div></td>
                                    @if ($languages !== [])<td class="num">{{ count($article->translatedLocales()) + 1 }} / {{ count($languages) + 1 }}</td>@endif
                                    <td class="end num" title="{{ __('Yes: :yes · No: :no', ['yes' => $article->helpful_yes, 'no' => $article->helpful_no]) }}">
                                        @if ($article->helpful_yes + $article->helpful_no > 0)
                                            {{ round($article->helpful_yes * 100 / ($article->helpful_yes + $article->helpful_no)) }}% <span class="faint">({{ $article->helpful_yes + $article->helpful_no }})</span>
                                        @else
                                            <span class="faint">—</span>
                                        @endif
                                    </td>
                                    <td>@if ($article->is_published)<x-pill tone="good">{{ __('Published') }}</x-pill>@else<x-pill>{{ __('Draft') }}</x-pill>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </section>
            @empty
                <section class="card empty">
                    <strong>{{ __('No articles yet') }}</strong>
                    {{ __('Start with a category such as “Getting started” or “Email”, then write the answers you give most often.') }}
                    <div style="margin-top:.8rem"><a class="btn btn-primary btn-sm" href="{{ route('admin.kb.categories.create') }}"><x-icon name="plus" />{{ __('New category') }}</a></div>
                </section>
            @endforelse
        </div>

        <form method="POST" action="{{ route('admin.kb.settings') }}" class="card" style="display:grid;gap:1rem">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('On your site') }}</h2></div>
            <x-checkbox name="enabled" :label="__('Show the knowledge base')" :help="__('Anyone can read published articles, also without an account. Search engines can find them.')" :checked="setting('knowledgebase.enabled')" />
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                @if (setting('knowledgebase.enabled'))
                    <a class="btn" href="{{ route('kb.index') }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open') }}</a>
                @endif
            </div>
        </form>
    </div>
</x-layouts.admin>
