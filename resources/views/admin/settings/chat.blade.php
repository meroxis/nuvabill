@php
    $channels = ['telegram' => 'Telegram', 'whatsapp' => 'WhatsApp'];
    $statusTone = ['APPROVED' => 'good', 'PENDING' => 'warn', 'IN_APPEAL' => 'warn', 'REJECTED' => 'crit', 'PAUSED' => 'crit', 'DISABLED' => 'crit'];
    $statusLabel = ['APPROVED' => __('Approved'), 'PENDING' => __('Waiting for Meta'), 'IN_APPEAL' => __('Waiting for Meta'), 'REJECTED' => __('Rejected'), 'PAUSED' => __('Paused'), 'DISABLED' => __('Turned off')];
@endphp
<x-layouts.admin :title="__('Chat apps')">
    <x-settings-page>

        <div class="grid-halves" style="align-items:start">
            <div style="display:grid;gap:14px;align-content:start;min-width:0">
                <section class="card" style="display:grid;gap:1rem" id="telegram">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
                        <h2 style="font-size:1.05rem">Telegram</h2>
                        @if ($telegram)
                            <span class="pill" data-tone="good">{{ __('Connected as @:bot', ['bot' => setting('chat.telegram_bot')]) }}</span>
                        @else
                            <span class="pill">{{ __('Not connected') }}</span>
                        @endif
                    </div>
                    @if ($telegram)
                        <p class="muted" style="margin:0;font-size:.9rem">{{ trans_choice(':count client has linked Telegram.|:count clients have linked Telegram.', $links['telegram'] ?? 0, ['count' => $links['telegram'] ?? 0]) }}</p>
                        <form method="POST" action="{{ route('admin.settings.chat.telegram.staff') }}" style="display:grid;gap:.6rem">
                            @csrf
                            @method('PUT')
                            <x-select name="staff_chat" :label="__('Staff alerts go to')" :options="['' => __('Nowhere')] + $groups" :value="setting('chat.telegram_staff_chat')"
                                :help="__('Add @:bot to your team’s Telegram group, then reload this page and pick the group. New tickets, client replies and orders are posted there.', ['bot' => setting('chat.telegram_bot')])" />
                            <div><button class="btn btn-sm" type="submit">{{ __('Save') }}</button></div>
                        </form>
                        <form method="POST" action="{{ route('admin.settings.chat.telegram.destroy') }}" onsubmit="return confirm(@js(__('Disconnect Telegram? Clients stop getting messages there.')))">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-ghost" type="submit">{{ __('Disconnect Telegram') }}</button>
                        </form>
                    @else
                        <ol class="muted" style="margin:0;padding-inline-start:1.2rem;list-style:decimal;font-size:.9rem;display:grid;gap:.3rem">
                            <li>{{ __('In Telegram, open @BotFather and send /newbot.') }}</li>
                            <li>{{ __('Pick a name, like “YourHost Support”, and a username that ends in “bot”.') }}</li>
                            <li>{{ __('Copy the token @BotFather sends and paste it here.') }}</li>
                        </ol>
                        <form method="POST" action="{{ route('admin.settings.chat.telegram') }}" style="display:grid;gap:.6rem">
                            @csrf
                            @method('PUT')
                            <x-input name="token" type="password" :label="__('Bot token')" autocomplete="off" spellcheck="false" placeholder="123456789:AAF…" :help="__('Free. Saved encrypted.')" />
                            <div><button class="btn btn-primary btn-sm" type="submit">{{ __('Connect Telegram') }}</button></div>
                        </form>
                    @endif
                </section>

                <section class="card" style="display:grid;gap:1rem" id="whatsapp">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
                        <h2 style="font-size:1.05rem">WhatsApp</h2>
                        @if ($whatsApp)
                            <span class="pill" data-tone="good">{{ __('Connected: :number', ['number' => setting('chat.whatsapp_number')]) }}</span>
                        @else
                            <span class="pill">{{ __('Not connected') }}</span>
                        @endif
                    </div>
                    <p class="muted" style="margin:0;font-size:.9rem">{{ __('Uses Meta’s official WhatsApp Business Platform. Meta charges a small fee for messages you send first, like invoice reminders; replies to clients within 24 hours are free.') }}</p>

                    @if ($whatsApp)
                        <p style="margin:0;font-size:.9rem">
                            <b>{{ setting('chat.whatsapp_name') ?: setting('chat.whatsapp_number') }}</b>
                            · {{ setting('chat.whatsapp_via') === 'qr' ? __('connected by QR code') : __('connected with your own Meta app') }}
                            · {{ trans_choice(':count client has linked WhatsApp.|:count clients have linked WhatsApp.', $links['whatsapp'] ?? 0, ['count' => $links['whatsapp'] ?? 0]) }}
                        </p>
                        <div style="display:grid;gap:.4rem">
                            <span class="label" style="margin:0">{{ __('Message templates') }}</span>
                            @foreach ($events as $event => $definition)
                                <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;font-size:.9rem;flex-wrap:wrap">
                                    <span>{{ __($definition['label']) }}</span>
                                    <span style="display:flex;gap:4px;flex-wrap:wrap">
                                        @foreach ($templateLanguages as $language)
                                            @php $status = $templates[$definition['template']][$language] ?? null; @endphp
                                            <span class="pill" data-tone="{{ $statusTone[$status] ?? '' }}" title="{{ $language }}">{{ $language }} · {{ $status ? ($statusLabel[$status] ?? $status) : __('Not sent') }}</span>
                                        @endforeach
                                    </span>
                                </div>
                            @endforeach
                            <p class="help" style="margin:0">{{ __('Messages you send first must use templates Meta approved. Nuvabill sends them to Meta for you; Meta usually checks them within a day. Replies within 24 hours need no template.') }}</p>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <form method="POST" action="{{ route('admin.settings.chat.whatsapp.templates') }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="refresh" />{{ __('Check templates again') }}</button></form>
                            <form method="POST" action="{{ route('admin.settings.chat.whatsapp.destroy') }}" onsubmit="return confirm(@js(__('Disconnect WhatsApp? Clients stop getting messages there.')))">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-ghost" type="submit">{{ __('Disconnect WhatsApp') }}</button>
                            </form>
                        </div>
                    @else
                        @if (! $qrReady)
                        <p class="flash" data-tone="warn" style="margin:0">{{ __('Connecting by QR code is not available yet. It opens as soon as Meta has approved Nuvabill. Until then, use your own Meta app below.') }}</p>
                        @else
                        <div x-data="whatsappConnect(@js([
                            'url' => $connectUrl,
                            'origin' => $connectOrigin,
                            'state' => $state,
                            'endpoint' => route('admin.settings.chat.whatsapp.connect'),
                            'failed' => __('WhatsApp could not be connected. Please try again.'),
                            'blocked' => __('Your browser blocked the window. Allow pop-ups for this site and try again.'),
                            'closed' => __('The window closed before WhatsApp was connected. Press the button to try again.'),
                        ]))" style="display:grid;gap:.6rem">
                            <ol class="muted" style="margin:0;padding-inline-start:1.2rem;list-style:decimal;font-size:.9rem;display:grid;gap:.3rem">
                                <li>{{ __('Press the button. Meta’s WhatsApp signup opens in a new window.') }}</li>
                                <li>{{ __('Sign in with Facebook and pick your business.') }}</li>
                                <li>{{ __('Open the WhatsApp Business app on your phone and scan the QR code. Your number keeps working in the app.') }}</li>
                            </ol>
                            <div><button class="btn btn-primary btn-sm" type="button" @click="open" :disabled="busy"><x-icon name="plug" /><span x-text="busy ? @js(__('Connecting…')) : @js(__('Connect with a QR code'))"></span></button></div>
                            <p class="flash" data-tone="crit" x-show="error" x-text="error" x-cloak role="alert" style="margin:0"></p>
                        </div>
                        @endif

                        <details @if (! $qrReady) open @endif>
                            <summary style="cursor:pointer;font-weight:600">{{ __('Use your own Meta app instead') }}</summary>
                            <form method="POST" action="{{ route('admin.settings.chat.whatsapp.manual') }}" style="display:grid;gap:.8rem;margin-top:.8rem">
                                @csrf
                                @method('PUT')
                                <p class="help" style="margin:0">{{ __('For businesses that already have a Meta app with WhatsApp. Find these in the Meta app dashboard → WhatsApp → API setup. Nuvabill points the account’s webhooks at this site for you.') }}</p>
                                <div class="form-grid">
                                    <x-input name="phone_number_id" :label="__('Phone number ID')" inputmode="numeric" autocomplete="off" />
                                    <x-input name="waba_id" :label="__('WhatsApp Business account ID')" inputmode="numeric" autocomplete="off" />
                                    <x-input name="token" type="password" :label="__('Permanent access token')" autocomplete="off" spellcheck="false" :help="__('From a system user in Meta Business settings.')" />
                                    <x-input name="app_secret" type="password" :label="__('App secret')" autocomplete="off" spellcheck="false" :help="__('App settings → Basic. Used to check that messages really come from Meta.')" />
                                </div>
                                <div><button class="btn btn-sm" type="submit">{{ __('Connect WhatsApp') }}</button></div>
                            </form>
                        </details>
                    @endif
                </section>
            </div>

            <div style="display:grid;gap:14px;align-content:start;min-width:0">
                <form method="POST" action="{{ route('admin.settings.chat.messages') }}" class="card card-flush">
                    @csrf
                    @method('PUT')
                    <div style="padding:1rem 1.1rem .4rem">
                        <h2 style="font-size:1.05rem">{{ __('Which messages go where') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Sent next to the email, to clients who linked the app, in their own language. Emails always go out as before.') }}</p>
                    </div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Message') }}</th>@foreach ($channels as $label)<th style="text-align:center">{{ $label }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($events as $event => $definition)
                                <tr>
                                    <td>{{ __($definition['label']) }}</td>
                                    @foreach ($channels as $channel => $label)
                                        <td style="text-align:center">
                                            <input type="checkbox" name="events[{{ $event }}][{{ $channel }}]" value="1" @checked($matrix[$event][$channel] ?? false) aria-label="{{ __(':message on :app', ['message' => __($definition['label']), 'app' => $label]) }}" style="width:17px;height:17px;accent-color:var(--nb-accent)">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    <div style="display:grid;gap:.8rem;padding:1rem 1.1rem">
                        <x-select name="department" :label="__('Tickets from chats go to')" :options="['' => __('The first department')] + $departments" :value="setting('chat.department')"
                            :help="__('When a linked client writes something that is not a command, it becomes a ticket, or a reply on their open chat ticket.')" />
                        <x-checkbox name="whatsapp_tickets" :label="__('Turn WhatsApp messages from clients into tickets')" :checked="setting('chat.whatsapp_tickets')"
                            :help="__('Turn this off if you answer clients yourself in the WhatsApp Business app. Commands like “invoices” still work.')" />
                        <div><button class="btn btn-primary" type="submit">{{ __('Save settings') }}</button></div>
                    </div>
                </form>

                <section class="card" style="display:grid;gap:.5rem">
                    <h2 style="font-size:1.05rem">{{ __('In the client area') }}</h2>
                    <p class="muted" style="margin:0;font-size:.9rem">{{ __('Clients open Account → “Get alerts on your phone” and scan a QR code. Their chat app opens and the account is linked. They can ask for their invoices, services and tickets, write to support, and disconnect any time with /stop.') }}</p>
                </section>
            </div>
        </div>
    </x-settings-page>
</x-layouts.admin>
