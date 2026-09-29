@php
    $languageName = fn (?string $code): string => isset(\App\Support\Locales::ALL[(string) $code]) ? \App\Support\Locales::displayName((string) $code) : (string) $code;
    $direction = fn (?string $code): string => (\App\Support\Locales::ALL[(string) $code]['rtl'] ?? false) ? 'rtl' : 'auto';
@endphp
<x-layouts.admin :title="$ticket->subject">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Ticket') }} #{{ $ticket->number }} · {{ $ticket->department->name }}</p>
            <h1 style="margin-top:.2rem">{{ $ticket->subject }}</h1>
            <p><a href="{{ route('admin.clients.show', $ticket->client) }}">{{ $ticket->client->name }}</a> · {{ $ticket->client->email }}
                @if ($ticket->service) · {{ __('About :service', ['service' => $ticket->service->label()]) }}@endif
            </p>
        </div>
        <div class="form-actions">
            <form method="POST" action="{{ route('admin.tickets.assign', $ticket) }}" style="display:flex;gap:6px;align-items:center">
                @csrf
                <label for="f-assign" class="muted" style="font-size:.85rem">{{ __('Assigned to') }}</label>
                <select id="f-assign" name="admin" class="select" style="width:auto" data-autosubmit>
                    <option value="">{{ __('Nobody') }}</option>
                    @foreach ($staff as $id => $name)
                        <option value="{{ $id }}" @selected((int) $ticket->assigned_admin_id === (int) $id)>{{ $name }}</option>
                    @endforeach
                </select>
                <noscript><button class="btn btn-sm" type="submit">{{ __('Assign') }}</button></noscript>
            </form>
            <x-status :value="$ticket->status" />
            <x-status :value="$ticket->priority" />
            @if ($ticket->status !== \App\Enums\TicketStatus::Closed)
                <form method="POST" action="{{ route('admin.tickets.close', $ticket) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Close ticket') }}</button></form>
            @endif
        </div>
    </div>

    <div @class(['ticket-layout' => $ai['ready']])
        @if ($ai['ready'])
            x-data="ticketAi(@js([
                'urls' => [
                    'summary' => route('admin.tickets.ai.summary', $ticket),
                    'draft' => route('admin.tickets.ai.draft', $ticket),
                    'translate' => route('admin.tickets.ai.translate', $ticket),
                ],
                'reply' => (string) old('message', ''),
                'summary' => $ai['summary'],
                'stale' => $ai['summaryStale'],
                'translate' => $ai['translateReply'] && old('translate', '1') !== '0',
                'failed' => __('AI help could not answer. Please try again.'),
            ]))"
        @endif
    >
        <div style="display:grid;gap:14px;align-content:start;min-width:0">
            <div class="thread">
                @foreach ($ticket->replies as $reply)
                    <article class="reply {{ $reply->isFromStaff() ? 'staff' : '' }}">
                        <span class="avatar">{{ mb_strtoupper(mb_substr($reply->authorName(), 0, 1)) }}</span>
                        <div class="bubble">
                            <header>
                                <b>{{ $reply->authorName() }}</b><span>{{ $reply->isFromStaff() ? __('Staff') : __('Client') }}</span><time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $reply->created_at->translatedFormat('d M Y H:i') }}</time>
                                @if ($reply->original_message !== null)
                                    <span class="pill" data-tone="info">{{ __('Sent in :language', ['language' => $languageName($reply->language)]) }}</span>
                                @elseif ($reply->translation !== null)
                                    <span class="pill" data-tone="info">{{ __('Translated from :language', ['language' => $languageName($reply->language)]) }}</span>
                                @endif
                            </header>
                            @if ($reply->original_message !== null)
                                <div class="message-body">{{ $reply->original_message }}</div>
                                <details class="ai-original">
                                    <summary>{{ __('What the client got') }}</summary>
                                    <div class="message-body" lang="{{ \App\Support\Locales::htmlLang($reply->language) }}" dir="{{ $direction($reply->language) }}">{{ $reply->message }}</div>
                                </details>
                            @elseif ($reply->translation !== null)
                                <div class="message-body">{{ $reply->translation }}</div>
                                <details class="ai-original">
                                    <summary>{{ __('Show the original') }}</summary>
                                    <div class="message-body" lang="{{ \App\Support\Locales::htmlLang($reply->language) }}" dir="{{ $direction($reply->language) }}">{{ $reply->message }}</div>
                                </details>
                            @elseif ($ai['translateIncoming'] && \App\Ai\TicketAssistant::looksForeign($reply))
                                <div x-data="aiMessage(@js(['url' => route('admin.tickets.ai.message', [$ticket, $reply]), 'failed' => __('AI help could not answer. Please try again.')]))">
                                    <div class="message-body" x-show="!translation" dir="auto">{{ $reply->message }}</div>
                                    <template x-if="translation">
                                        <div>
                                            <div class="message-body" x-text="translation"></div>
                                            <details class="ai-original">
                                                <summary>{{ __('Show the original') }}</summary>
                                                <div class="message-body" dir="auto">{{ $reply->message }}</div>
                                            </details>
                                        </div>
                                    </template>
                                    <div class="ai-inline">
                                        <button type="button" class="btn btn-sm btn-ghost" x-show="!translation" @click="translate" :disabled="busy"><x-icon name="globe" /><span x-text="busy ? @js(__('Translating…')) : @js(__('Translate'))"></span></button>
                                        <span class="muted" x-show="message" x-text="message" role="status"></span>
                                    </div>
                                </div>
                            @else
                                <div class="message-body" dir="auto">{{ $reply->message }}</div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" class="card" style="display:grid;gap:1rem" @if ($ai['ready']) @submit="send($event)" @endif>
                @csrf
                <div class="field">
                    <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap">
                        <label for="f-message">{{ __('Your reply') }} <span aria-hidden="true">*</span></label>
                        @if ($ai['drafts'])
                            <span class="pill" data-tone="accent" x-show="drafted" x-cloak>{{ __('Draft by AI · check before sending') }}</span>
                        @endif
                    </div>
                    <textarea id="f-message" name="message" class="textarea" rows="8" required
                        placeholder="{{ __('Hi :name,', ['name' => $ticket->client->first_name]) }}"
                        @error('message') aria-invalid="true" @enderror
                        @if ($ai['ready']) x-model="reply" x-ref="reply" @endif>{{ old('message') }}</textarea>
                    @error('message')<p class="error">{{ $message }}</p>@enderror
                </div>

                @if ($ai['drafts'])
                    <div class="ai-tools">
                        <button type="button" class="btn btn-sm" @click="draft()" :disabled="busy !== ''">
                            <x-icon name="sparkles" /><span x-text="reply.trim() === '' ? @js(__('Write a draft')) : @js(__('Write a new draft'))"></span>
                        </button>
                        <template x-if="reply.trim() !== ''">
                            <div class="ai-tools">
                                @foreach (['shorter' => __('Shorter'), 'friendlier' => __('Friendlier'), 'detail' => __('More detail')] as $tone => $label)
                                    <button type="button" class="btn btn-sm btn-ghost" @click="draft(@js($tone))" :disabled="busy !== ''">{{ $label }}</button>
                                @endforeach
                            </div>
                        </template>
                        <span class="muted" x-show="busy === 'draft'" x-cloak role="status">{{ __('Writing…') }}</span>
                    </div>
                @endif

                @if ($ai['translateReply'])
                    <div class="field">
                        <input type="hidden" name="translate" value="0">
                        <label class="check" for="f-translate">
                            <input id="f-translate" type="checkbox" name="translate" value="1" x-model="sendTranslated">
                            <span>{{ __('Send it in :language', ['language' => $ai['clientLanguage']]) }}<br><span class="help">{{ __(':name wrote in :language. You check the translation before it is sent.', ['name' => $ticket->client->first_name, 'language' => $ai['clientLanguage']]) }}</span></span>
                        </label>
                    </div>
                    <div class="ai-translation" x-show="sendTranslated && translation !== ''" x-cloak>
                        <span class="muted" style="font-size:.85rem;font-weight:700">
                            {{ __(':name gets it in :language', ['name' => $ticket->client->first_name, 'language' => $ai['clientLanguage']]) }}
                            <span x-show="!fresh()" style="font-weight:400"> · {{ __('your reply changed, so it is translated again when you send') }}</span>
                        </span>
                        <div class="message-body" dir="auto" x-text="translation"></div>
                    </div>
                    <input type="hidden" name="translation" :value="fresh() ? translation : ''">
                    <input type="hidden" name="translated_from" :value="fresh() ? translatedFrom : ''">
                @endif

                @if ($ai['ready'])
                    <p class="flash" data-tone="crit" x-show="error" x-text="error" x-cloak role="alert" style="margin:0"></p>
                    <p class="flash" data-tone="info" x-show="notice" x-text="notice" x-cloak role="status" style="margin:0"></p>
                @endif

                <div class="form-actions">
                    <select name="status" class="select" style="width:auto" aria-label="{{ __('Status after reply') }}">
                        <option value="answered">{{ __('Send and mark answered') }}</option>
                        <option value="on_hold">{{ __('Send and put on hold') }}</option>
                        <option value="closed">{{ __('Send and close') }}</option>
                    </select>
                    <button class="btn btn-primary" type="submit" @if ($ai['ready']) :disabled="busy !== ''" @endif>
                        <x-icon name="mail" />
                        @if ($ai['translateReply'])
                            <span x-text="sendTranslated && !fresh() ? @js(__('Check the translation')) : @js(__('Send reply'))">{{ __('Send reply') }}</span>
                        @else
                            {{ __('Send reply') }}
                        @endif
                    </button>
                </div>
                @if ($ai['ready'])
                    <p class="muted" style="margin:0;font-size:.82rem">{{ __('Staff always send. AI never replies by itself.') }}</p>
                @elseif ($ai['setUp'])
                    <p class="muted" style="margin:0;font-size:.82rem">{{ __('AI help can write reply drafts and translate tickets.') }} <a href="{{ route('admin.settings.ai.edit') }}">{{ __('Set it up in Settings → AI') }}</a></p>
                @endif
            </form>
        </div>

        @if ($ai['ready'] && ($ai['summaries'] || $ai['drafts']))
            <aside style="display:grid;gap:14px;align-content:start;min-width:0">
                @if ($ai['summaries'])
                    <section class="card" style="display:grid;gap:10px" aria-labelledby="ai-summary-title">
                        <h2 id="ai-summary-title" class="ai-label">{{ __('AI summary') }}</h2>
                        <template x-if="summary">
                            <div style="display:grid;gap:8px">
                                <p style="margin:0;line-height:1.55" x-text="summary.summary"></p>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <span class="pill" x-show="summary.department" x-text="@js(__('Suggested: :department')).replace(':department', summary.department)"></span>
                                    <span class="pill" x-text="@js(__('Priority: :priority')).replace(':priority', summary.priority_label || summary.priority)"></span>
                                </div>
                            </div>
                        </template>
                        <p class="muted" style="margin:0;font-size:.88rem" x-show="!summary">{{ __('A short summary of the ticket, with a suggested department and priority.') }}</p>
                        <p class="muted" style="margin:0;font-size:.82rem" x-show="summary && stale" x-cloak>{{ __('There are new messages since this summary.') }}</p>
                        <div><button type="button" class="btn btn-sm" @click="summarize" :disabled="busy !== ''"><x-icon name="sparkles" />
                            <span x-text="busy === 'summary' ? @js(__('Summarizing…')) : (summary ? @js(__('Update summary')) : @js(__('Summarize')))"></span></button></div>
                    </section>
                @endif
                @if ($ai['drafts'])
                    <section class="card" style="display:grid;gap:4px">
                        <h2 class="ai-label" style="margin-bottom:6px">{{ __('What drafts are based on') }}</h2>
                        @foreach ($ai['facts'] as $fact)
                            <div class="ai-fact"><span class="muted">{{ $fact['label'] }}</span><span>{{ $fact['value'] }}</span></div>
                        @endforeach
                        <p class="muted" style="margin:8px 0 0;font-size:.82rem">{{ __('Passwords, card details, email addresses and phone numbers are never sent to the AI.') }}</p>
                    </section>
                    <section class="card" style="display:grid;gap:10px">
                        <h2 class="ai-label">{{ __('Ask AI') }}</h2>
                        <label for="f-ai-instruction" class="muted" style="font-size:.88rem">{{ __('Tell the AI what the reply should say') }}</label>
                        <textarea id="f-ai-instruction" class="textarea" rows="3" maxlength="1000" x-model="instruction" placeholder="{{ __('For example: offer to fix it for them and mention our backups') }}"></textarea>
                        <div><button type="button" class="btn btn-sm" @click="draft()" :disabled="busy !== '' || instruction.trim() === ''"><x-icon name="sparkles" />{{ __('Write a draft with this') }}</button></div>
                    </section>
                @endif
            </aside>
        @endif
    </div>
</x-layouts.admin>
