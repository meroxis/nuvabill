<x-layouts.admin :title="__('AI')">
    <x-settings-page>

        <form method="POST" action="{{ route('admin.settings.ai.update') }}" id="ai-settings">
            @csrf
            @method('PUT')
        </form>

        <div class="grid-halves" style="align-items:start">
            <div style="display:grid;gap:14px;align-content:start;min-width:0">
                <section class="card" style="display:grid;gap:1rem">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap">
                        <h2 style="font-size:1.05rem">{{ __('AI provider') }}</h2>
                        @if ($hasKey)
                            <span class="pill" data-tone="good">{{ __('Key saved') }}</span>
                        @else
                            <span class="pill">{{ __('Not set up') }}</span>
                        @endif
                    </div>
                    <p class="muted" style="margin:0;font-size:.9rem">{{ __('Nuvabill uses Claude by Anthropic with your own key. You pay Anthropic for what you use; Nuvabill adds nothing.') }}</p>
                    <x-input form="ai-settings" name="key" type="password" :label="__('Anthropic API key')" autocomplete="off" spellcheck="false"
                        :placeholder="$hasKey ? __('Saved. Leave empty to keep it.') : 'sk-ant-…'"
                        :help="__('Saved encrypted. Make one at console.anthropic.com → API keys, and add some credit there.')" />
                    @if ($hasKey)
                        <x-checkbox form="ai-settings" name="remove_key" :label="__('Remove the saved key')" :help="__('AI help stops until you add a key again.')" />
                    @endif

                    <fieldset style="display:grid;gap:8px;border:0;margin:0;padding:0;min-width:0">
                        <legend class="label" style="margin-bottom:6px">{{ __('Model') }}</legend>
                        @foreach ($models as $id => $model)
                            <label class="ai-model">
                                <input form="ai-settings" type="radio" name="model" value="{{ $id }}" @checked(old('model', $currentModel) === $id)>
                                <span><b>{{ __($model['label']) }}</b> · {{ $model['name'] }}
                                    <span class="help" style="display:block">{{ __($model['help']) }} {{ __(':input per million tokens read and :output per million written. A token is about ¾ of a word.', ['input' => '$'.number_format($model['input'], 2), 'output' => '$'.number_format($model['output'], 2)]) }}</span>
                                </span>
                            </label>
                        @endforeach
                        @error('model')<p class="error">{{ $message }}</p>@enderror
                    </fieldset>

                    @if ($hasKey)
                        <form method="POST" action="{{ route('admin.settings.ai.test') }}">
                            @csrf
                            <button class="btn btn-sm" type="submit"><x-icon name="zap" />{{ __('Test the key') }}</button>
                            <span class="help">{{ __('Sends one tiny request with the saved key and model.') }}</span>
                        </form>
                    @endif
                </section>

                <section class="card" style="display:grid;gap:1rem">
                    <h2 style="font-size:1.05rem">{{ __('Spending') }}</h2>
                    <div class="form-grid">
                        <x-input form="ai-settings" name="monthly_limit" type="number" step="0.01" min="0" max="10000" :label="__('Monthly limit in US dollars')"
                            :value="number_format(setting('ai.monthly_limit') / 100, 2, '.', '')" />
                        <div class="field">
                            <span class="label" style="margin:0">{{ __('Used this month') }}</span>
                            <p style="margin:.45rem 0 0;font-weight:700">{{ \App\Ai\Claude::dollars($spent) }} · {{ trans_choice(':count request|:count requests', $requests, ['count' => number_format($requests)]) }}</p>
                            @if ($limit > 0)
                                <div class="length-meter" data-state="{{ $spent >= $limit * 0.8 ? 'long' : 'good' }}"><span><i style="width:{{ min(100, (int) round($spent / $limit * 100)) }}%"></i></span></div>
                            @endif
                        </div>
                    </div>
                    <p class="help" style="margin:0">{{ __('When the limit is reached, AI help pauses until the next month. Your company email gets a note at 80%. 0 means no limit.') }}</p>
                </section>
            </div>

            <div style="display:grid;gap:14px;align-content:start;min-width:0">
                <section class="card" style="display:grid;gap:1rem">
                    <h2 style="font-size:1.05rem">{{ __('What AI may help with') }}</h2>
                    <x-checkbox form="ai-settings" name="drafts" :label="__('Draft ticket replies')" :checked="setting('ai.drafts')" :help="__('Staff check and send every reply. AI never replies by itself.')" />
                    <x-checkbox form="ai-settings" name="translate" :label="__('Translate tickets both ways')" :checked="setting('ai.translate')" :help="__('Clients write and read in their own language; your team reads and writes in theirs.')" />
                    <x-checkbox form="ai-settings" name="summaries" :label="__('Summarize tickets and suggest a department and priority')" :checked="setting('ai.summaries')" />
                    <x-checkbox form="ai-settings" name="descriptions" :label="__('Write product and search descriptions')" :checked="setting('ai.descriptions')" :help="__('A “Write with AI” button on product pages.')" />
                    <x-select form="ai-settings" name="staff_language" :label="__('Your team reads and writes tickets in')" :options="$languages" :value="setting('ai.staff_language')" />
                </section>

                <section class="card" style="display:grid;gap:.8rem">
                    <h2 style="font-size:1.05rem">{{ __('What is shared with the AI') }}</h2>
                    <div class="grid-halves" style="font-size:.9rem">
                        <div style="display:grid;gap:6px;align-content:start">
                            <span class="pill" data-tone="good" style="justify-self:start">{{ __('Shared') }}</span>
                            <span>{{ __('Ticket messages') }}</span>
                            <span>{{ __('The client’s first name and language') }}</span>
                            <span>{{ __('Product and service names, domains') }}</span>
                            <span>{{ __('Service and invoice status') }}</span>
                        </div>
                        <div style="display:grid;gap:6px;align-content:start">
                            <span class="pill" data-tone="crit" style="justify-self:start">{{ __('Never shared') }}</span>
                            <span>{{ __('Passwords and API keys') }}</span>
                            <span>{{ __('Card and bank details') }}</span>
                            <span>{{ __('Email addresses and phone numbers') }}</span>
                            <span>{{ __('Client addresses and payment history') }}</span>
                        </div>
                    </div>
                    <p class="help" style="margin:0">{{ __('Staff see AI buttons when their role has the right “Use AI help for ticket replies and product texts”.') }} @if (auth('admin')->user()->hasPermission('staff.manage'))<a href="{{ route('admin.settings.roles.index') }}">{{ __('Roles') }}</a>@endif</p>
                </section>

                <div><button class="btn btn-primary" type="submit" form="ai-settings">{{ __('Save settings') }}</button></div>
            </div>
        </div>
    </x-settings-page>
</x-layouts.admin>
