<x-layouts.admin :title="__('Review queue')">
    <div class="page-head">
        <div>
            <h1>{{ __('Marketplace store') }}</h1>
            <p>{{ __('Approved this week: :count', ['count' => $approvedThisWeek]) }}</p>
        </div>
    </div>
    @include('admin.store.nav')

    <div class="review-layout">
        <div class="review-list">
            <div class="filters" style="margin:0">
                <a class="chip" href="{{ route('admin.store.reviews.index') }}" @if ($status === 'waiting') aria-current="true" @endif>{{ __('Waiting (:count)', ['count' => $counts['waiting']]) }}</a>
                <a class="chip" href="{{ route('admin.store.reviews.index', ['status' => 'developer']) }}" @if ($status === 'developer') aria-current="true" @endif>{{ __('With developer (:count)', ['count' => $counts['developer']]) }}</a>
                <a class="chip" href="{{ route('admin.store.reviews.index', ['status' => 'done']) }}" @if ($status === 'done') aria-current="true" @endif>{{ __('Approved') }}</a>
            </div>
            @forelse ($queue as $entry)
                <a class="review-entry" href="{{ route('admin.store.reviews.show', [$entry, 'status' => $status === 'waiting' ? null : $status]) }}" @if ($version?->id === $entry->id) aria-current="true" @endif>
                    <b>{{ $entry->item->name }} <span class="faint" style="font-weight:500">{{ $entry->version }}</span></b>
                    <span class="faint" style="font-size:.8rem">{{ $entry->item->developer->name }} · {{ $entry->created_at->diffForHumans(short: true) }}</span>
                    <span style="display:flex;gap:6px;flex-wrap:wrap">
                        <x-pill>{{ $entry->item->latest_version_id ? __('Update') : __('New item') }}</x-pill>
                        @if ($entry->warnings())<x-pill tone="warn">{{ trans_choice(':count thing to look at|:count things to look at', $entry->warnings(), ['count' => $entry->warnings()]) }}</x-pill>@else<x-pill tone="good">{{ __('Checks passed') }}</x-pill>@endif
                    </span>
                </a>
            @empty
                <div class="empty">{{ __('Nothing here.') }}</div>
            @endforelse
            @foreach ($listings as $listed)
                <a class="review-entry" href="{{ route('admin.store.items.edit', $listed) }}">
                    <b>{{ $listed->name }}</b>
                    <span class="faint" style="font-size:.8rem">{{ $listed->developer->name }} · {{ $listed->updated_at->diffForHumans(short: true) }}</span>
                    <span style="display:flex;gap:6px;flex-wrap:wrap"><x-pill tone="warn">{{ __('Listing changes waiting') }}</x-pill></span>
                </a>
            @endforeach
        </div>

        @if ($version)
            <div style="display:grid;gap:14px;align-content:start;min-width:0">
                <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
                    <div style="flex:1;min-width:0">
                        <h2 style="font-size:1.5rem">{{ $version->item->name }} <span class="muted" style="font-size:1rem">{{ $version->version }}</span></h2>
                        <p class="muted" style="margin:0">{{ $version->item->type->label() }} · {{ __('by :name', ['name' => $version->item->developer->name]) }}@if ($version->item->developer->is_verified) · {{ __('verified developer') }}@endif · {{ trans_choice(':count live item|:count live items', $version->item->developer->items->filter->isLive()->count(), ['count' => $version->item->developer->items->filter->isLive()->count()]) }} · {{ $version->created_at->diffForHumans() }}</p>
                    </div>
                    <div style="text-align:right"><b style="font-size:1.4rem">{{ $version->item->isFree() ? __('Free') : money($version->item->price, $version->item->currency) }}</b>@if ($version->item->update_price)<div class="faint" style="font-size:.8rem">{{ __('updates :price a year', ['price' => money($version->item->update_price, $version->item->currency)]) }}</div>@endif</div>
                </div>

                <div class="grid-2">
                    <section class="card" style="display:grid;gap:.6rem">
                        <h3 style="font-size:1rem">{{ __('Automatic checks') }}</h3>
                        @foreach ((array) $version->checks as $check)
                            <div class="market-perm"><span class="dot {{ $check['level'] === 'ok' ? '' : 'no' }}" @if ($check['level'] !== 'ok') style="background:var(--nb-{{ $check['level'] === 'fail' ? 'crit' : 'warn' }}-soft);color:var(--nb-{{ $check['level'] === 'fail' ? 'crit' : 'warn' }})" @endif><x-icon :name="$check['level'] === 'ok' ? 'check' : 'alert'" /></span><span><b>{{ $check['title'] }}</b><br><span class="muted" style="overflow-wrap:anywhere">{{ $check['text'] }}</span></span></div>
                        @endforeach
                    </section>

                    <form method="POST" action="{{ route('admin.store.reviews.checklist', $version) }}" class="card" style="display:grid;gap:.6rem">
                        @csrf
                        @method('PUT')
                        <h3 style="font-size:1rem">{{ __('Your checklist') }}</h3>
                        @foreach (\App\Models\MarketplaceVersion::CHECKLIST as $key => $label)
                            <label class="check"><input type="checkbox" name="checklist[]" value="{{ $key }}" @checked(in_array($key, (array) $version->checklist, true))> {{ __($label) }}</label>
                        @endforeach
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button class="btn btn-sm" type="submit">{{ __('Save checklist') }}</button>
                            <a class="btn btn-sm" href="{{ route('admin.store.reviews.download', $version) }}"><x-icon name="download" />{{ __('Download to test') }}</a>
                        </div>
                    </form>
                </div>

                @if ($version->changelog || $version->messages->isNotEmpty())
                    <section class="card" style="display:grid;gap:.6rem">
                        <h3 style="font-size:1rem">{{ __('Messages') }}</h3>
                        @if ($version->changelog)<p class="muted" style="margin:0"><b>{{ __('What is new:') }}</b> {{ $version->changelog }}</p>@endif
                        @foreach ($version->messages as $message)
                            <div class="message-body" style="padding:.6rem .8rem;border-radius:var(--nb-radius);background:var(--nb-surface-2)"><b>{{ $message->authorName() }}</b> <span class="faint" style="font-size:.8rem">{{ $message->created_at->diffForHumans() }}</span><br>{{ $message->message }}</div>
                        @endforeach
                    </section>
                @endif

                @if ($version->status === \App\Models\MarketplaceVersion::STATUS_PENDING || $version->status === \App\Models\MarketplaceVersion::STATUS_CHANGES)
                    <section class="card" style="display:grid;gap:.8rem">
                        <div class="field">
                            <label for="review-message">{{ __('Message to the developer') }}</label>
                            <textarea class="textarea" id="review-message" rows="3" form="review-changes" name="message" placeholder="{{ __('What works well, and what to change.') }}"></textarea>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                            <form method="POST" action="{{ route('admin.store.reviews.reject', $version) }}" onsubmit="this.querySelector('[name=message]').value = document.getElementById('review-message').value; return confirm(@js(__('Reject this version?')))">
                                @csrf
                                <input type="hidden" name="message">
                                <button class="btn" type="submit" style="color:var(--nb-crit)">{{ __('Reject') }}</button>
                            </form>
                            <span style="flex:1" class="faint">{{ __('Approving signs the package with the marketplace key and puts it live.') }}</span>
                            <form method="POST" action="{{ route('admin.store.reviews.changes', $version) }}" id="review-changes">
                                @csrf
                                <button class="btn" type="submit">{{ __('Ask for changes') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.store.reviews.approve', $version) }}" onsubmit="this.querySelector('[name=message]').value = document.getElementById('review-message').value">
                                @csrf
                                <input type="hidden" name="message">
                                <button class="btn btn-primary" type="submit" @disabled($version->hasFailures())><x-icon name="shield" />{{ __('Approve and sign') }}</button>
                            </form>
                        </div>
                    </section>
                @endif
            </div>
        @else
            <section class="card"><div class="empty"><strong>{{ __('Nothing to review') }}</strong>{{ __('New versions from developers show up here.') }}</div></section>
        @endif
    </div>
</x-layouts.admin>
