<x-layouts.admin :title="__('Settings')">
    @php $lastRun = $settings['automation.last_run_at'] ? \Illuminate\Support\Carbon::parse($settings['automation.last_run_at']) : null; @endphp
    <x-settings-page>

        <form method="POST" action="{{ route('admin.settings.update') }}" style="display:grid;gap:14px">
            @csrf
            @method('PUT')

            <section class="card" style="display:grid;gap:1.1rem">
                <div class="card-header" style="margin:0"><h2>{{ __('Your company') }}</h2></div>
                <div class="form-grid">
                    <x-input name="company_name" :label="__('Company name')" :value="$settings['company.name']" required />
                    <x-input name="company_email" type="email" :label="__('Billing email')" :value="$settings['company.email']" required :help="__('Shown on invoices. New order and ticket alerts go here.')" />
                    <x-input name="company_phone" :label="__('Phone')" :value="$settings['company.phone']" />
                    <x-input name="terms_url" type="url" :label="__('Terms of service link')" :value="$settings['orders.accept_terms_url']" :help="__('If set, clients must accept your terms at checkout.')" />
                    <x-input name="privacy_url" type="url" :label="__('Privacy policy link')" :value="$settings['company.privacy_url']" :help="__('Shown when clients sign up and in the client area footer. Google and Facebook sign-in need it.')" />
                    <x-textarea name="company_address" :label="__('Address on invoices')" :value="$settings['company.address']" rows="3" class="span-2" />
                </div>
            </section>

            <section class="card" style="display:grid;gap:1.1rem">
                <div class="card-header" style="margin:0"><h2>{{ __('Billing') }}</h2></div>
                <div class="form-grid">
                    <x-select name="currency" :label="__('Currency')" :options="$currencies" :value="$settings['billing.currency']" required :help="__('Choose this before your first order. Existing prices and invoices are not converted.')" />
                    <x-input name="invoice_prefix" :label="__('Invoice number prefix')" :value="$settings['billing.invoice_prefix']" :help="__('For example INV- gives INV-0042.')" />
                    <x-input name="renewal_days_before" type="number" min="0" max="60" :label="__('Create renewal invoices this many days before the due date')" :value="$settings['billing.renewal_days_before']" required />
                    <x-input name="payment_terms_days" type="number" min="0" max="90" :label="__('Days to pay manual invoices')" :value="$settings['billing.payment_terms_days']" required />
                </div>
            </section>

            <section class="card" style="display:grid;gap:1.1rem" id="automation">
                <div class="card-header" style="margin:0">
                    <h2>{{ __('Automation') }}</h2>
                    @if ($lastRun)
                        <x-pill :tone="$lastRun->gt(now()->subHours(26)) ? 'good' : 'crit'">{{ __('Last run :time', ['time' => $lastRun->diffForHumans()]) }}</x-pill>
                    @else
                        <x-pill tone="crit">{{ __('Never run') }}</x-pill>
                    @endif
                </div>
                <div class="form-grid">
                    <x-checkbox name="automation_enabled" :label="__('Run the daily automation')" :help="__('Creates renewal invoices, sends reminders and suspends overdue services every night.')" :checked="$settings['automation.enabled']" class="span-2" />
                    <x-input name="reminder_days" :label="__('Send overdue reminders after (days)')" :value="implode(', ', (array) $settings['automation.reminder_days'])" :help="__('Comma separated, for example 1, 3, 7.')" />
                    <x-input name="suspend_days" type="number" min="0" max="90" :label="__('Suspend services overdue by (days)')" :value="$settings['automation.suspend_days']" required :help="__('0 turns automatic suspension off.')" />
                    <x-input name="terminate_days" type="number" min="0" max="365" :label="__('Terminate services overdue by (days)')" :value="$settings['automation.terminate_days']" required :help="__('0 turns it off. Termination deletes the account on the server.')" />
                </div>
                <div class="flash" data-tone="info" style="display:grid;gap:.5rem">
                    <span>{{ __('Add this line to your server’s crontab (cPanel → Cron Jobs) so automation, emails and updates run:') }}</span>
                    <code class="mono" style="overflow-wrap:anywhere">{{ $cronCommand }}</code>
                    <span><button type="button" class="btn btn-sm" data-copy="{{ $cronCommand }}" data-copied="{{ __('Copied') }}">{{ __('Copy command') }}</button></span>
                </div>
            </section>

            <section class="card" style="display:grid;gap:1.1rem">
                <div class="card-header" style="margin:0"><h2>{{ __('Look and feel') }}</h2></div>
                <div class="form-grid">
                    <div class="field">
                        <label for="f-accent">{{ __('Brand color') }}</label>
                        <input id="f-accent" type="color" name="accent" value="{{ old('accent', $settings['branding.accent']) }}" class="input" style="height:42px;padding:4px">
                        <p class="help">{{ __('Used for buttons and links in your client area and emails.') }}</p>
                    </div>
                    <x-select name="theme" :label="__('Client area theme')" :options="$themes" :value="$settings['theme.active']" required />
                    <x-select name="locale_default" :label="__('Default language')" :options="$languages" :value="$settings['locale.default']" required :help="__('For visitors who have not picked a language yet.')" />
                    <fieldset class="field" style="border:0;padding:0;margin:0">
                        <legend style="font-weight:600;font-size:.9rem;margin-bottom:.4rem">{{ __('Languages clients can pick') }}</legend>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px 14px">
                            @foreach ($languages as $code => $name)
                                <label class="check" for="f-locale-{{ $code }}">
                                    <input id="f-locale-{{ $code }}" type="checkbox" name="locale_enabled[]" value="{{ $code }}" @checked(in_array($code, (array) old('locale_enabled', $settings['locale.enabled']), true))>
                                    <span>{{ $name }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="help">{{ __('Arabic and Kurdish pages read right to left. Invoices, PDFs and emails stay in English.') }}</p>
                    </fieldset>
                </div>
            </section>

            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save settings') }}</button></div>
        </form>

        <section class="card" id="icon" style="display:grid;gap:1.1rem">
            <div class="card-header" style="margin:0">
                <h2>{{ __('Your icon') }}</h2>
                @unless ($icon['licensed'])<x-pill>{{ __('White-label License') }}</x-pill>@endunless
            </div>
            <div class="brand-icon-setting">
                <img class="brand-icon-preview" src="{{ $icon['url'] ?? asset('favicon.svg') }}" width="64" height="64" alt="{{ __('Current icon') }}">
                <div style="display:grid;gap:.8rem;min-width:0">
                    <p class="muted" style="margin:0">{{ __('Shown in the browser tab, next to your company name in the admin area and client area, and as the phone app icon.') }}</p>
                    @if ($icon['licensed'])
                        <form method="POST" action="{{ route('admin.settings.icon.store') }}" enctype="multipart/form-data" style="display:grid;gap:.6rem">
                            @csrf
                            <div class="field">
                                <label for="f-icon">{{ $icon['own'] ? __('Replace the icon') : __('Upload your icon') }}</label>
                                <input id="f-icon" class="input" style="max-width:100%" type="file" name="icon" accept="image/png" required @error('icon') aria-invalid="true" aria-describedby="f-icon-error" @enderror>
                                <p class="help">{{ __('A square PNG, at least :size × :size pixels, up to 2 MB.', ['size' => \App\Support\BrandIcon::MIN_SIZE]) }}</p>
                                @error('icon')<p class="error" id="f-icon-error">{{ $message }}</p>@enderror
                            </div>
                            <div><button class="btn btn-primary btn-sm" type="submit"><x-icon name="upload" />{{ __('Upload icon') }}</button></div>
                        </form>
                        @if ($icon['own'])
                            <form method="POST" action="{{ route('admin.settings.icon.destroy') }}" data-confirm="{{ __('Remove your icon and use the Nuvabill icon again?') }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-ghost" type="submit">{{ __('Use the Nuvabill icon') }}</button>
                            </form>
                        @endif
                    @else
                        <p style="margin:0">{{ __('Your own icon needs a White-label License.') }} <a href="{{ route('admin.settings.license.edit') }}">{{ __('About the license') }}</a></p>
                        @if ($icon['own'])<p class="muted" style="margin:0">{{ __('Your icon is saved and comes back when the license is valid again.') }}</p>@endif
                        @error('icon')<p class="error">{{ $message }}</p>@enderror
                    @endif
                </div>
            </div>
        </section>

        <form method="POST" action="{{ route('admin.settings.mail') }}" class="card" style="display:grid;gap:1.1rem" x-data="{ mailer: @js(old('mailer', $settings['mail.mailer'])) }">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('Sending email') }}</h2></div>
            <div class="form-grid">
                <div class="field">
                    <label for="f-mailer">{{ __('Send with') }}</label>
                    <select id="f-mailer" name="mailer" class="select" x-model="mailer">
                        <option value="smtp">{{ __('SMTP server (recommended)') }}</option>
                        <option value="sendmail">{{ __('This server (PHP sendmail)') }}</option>
                        <option value="log">{{ __('Do not send; write to the log (testing)') }}</option>
                    </select>
                </div>
                <div></div>
                <template x-if="mailer === 'smtp'">
                    <div class="form-grid span-2">
                        <x-input name="host" :label="__('SMTP host')" :value="$settings['mail.host']" placeholder="mail.example.com" />
                        <x-input name="port" type="number" :label="__('Port')" :value="$settings['mail.port']" />
                        <x-input name="username" :label="__('Username')" :value="$settings['mail.username']" autocomplete="off" />
                        <x-input name="password" type="password" :label="__('Password')" :help="filled($settings['mail.password']) ? __('Saved. Leave empty to keep it.') : null" autocomplete="new-password" />
                        <x-select name="encryption" :label="__('Encryption')" :options="['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => __('None')]" :value="$settings['mail.encryption']" />
                    </div>
                </template>
                <x-input name="from_address" type="email" :label="__('From address')" :value="$settings['mail.from_address']" required />
                <x-input name="from_name" :label="__('From name')" :value="$settings['mail.from_name']" required />
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ __('Save email settings') }}</button>
            </div>
        </form>
        <form method="POST" action="{{ route('admin.settings.mail.test') }}">
            @csrf
            <button class="btn btn-sm" type="submit"><x-icon name="mail" />{{ __('Send me a test email') }}</button>
        </form>
    </x-settings-page>
</x-layouts.admin>
