<x-layouts.guest :title="__('Connect WhatsApp')" :subtitle="$valid ? __('To :site', ['site' => $siteHost]) : null">
    @if (! $ready)
        <p style="margin:0">{{ __('Connecting WhatsApp by QR code is not available yet. It opens as soon as Meta has approved Nuvabill. Until then, use your own Meta app in Settings → Chat apps.') }}</p>
    @elseif (! $valid)
        <p style="margin:0">{{ __('This page must be opened from Settings → Chat apps in your Nuvabill admin area.') }}</p>
    @else
        <ol class="muted" style="margin:0;padding-inline-start:1.2rem;list-style:decimal;display:grid;gap:.4rem">
            <li>{{ __('Press the button and sign in with Facebook.') }}</li>
            <li>{{ __('Pick your business, then choose to connect your existing WhatsApp Business app number.') }}</li>
            <li>{{ __('Open the WhatsApp Business app on your phone and scan the QR code Meta shows.') }}</li>
        </ol>
        <p class="muted" style="margin:0;font-size:.88rem">{{ __('Your number keeps working in the WhatsApp Business app. The access key goes back to :site only; this page keeps nothing.', ['site' => $siteHost]) }}</p>
        <button id="connect-start" class="btn btn-primary" type="button" style="width:100%">{{ __('Continue with Facebook') }}</button>
        <p id="connect-status" class="muted" role="status" style="margin:0;min-height:1.4em"></p>

        <script>
            window.fbAsyncInit = function () {
                FB.init({ appId: @js($appId), autoLogAppEvents: true, xfbml: false, version: @js($graphVersion) });
            };
        </script>
        <script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
        <script>
            (() => {
                const target = @js($targetOrigin);
                const state = @js($state);
                const text = @js([
                    'cancelled' => __('Signup was cancelled. Press the button to start again.'),
                    'failed' => __('WhatsApp could not be connected. Please try again.'),
                    'finishing' => __('Finishing…'),
                    'done' => __('Done. You can close this window.'),
                    'noOpener' => __('The Nuvabill page that opened this window was closed. Start again from Settings → Chat apps.'),
                ]);
                const status = document.getElementById('connect-status');
                let session = null;
                let code = null;
                let sent = false;

                const report = (data) => {
                    if (!window.opener) {
                        status.textContent = text.noOpener;

                        return false;
                    }

                    window.opener.postMessage({ source: 'nuvabill-whatsapp', state, ...data }, target);

                    return true;
                };

                const fail = (message) => {
                    status.textContent = message;
                    report({ error: message });
                };

                const finish = async () => {
                    if (!session || !code || sent) {
                        return;
                    }

                    sent = true;
                    status.textContent = text.finishing;

                    const response = await fetch(@js(route('connect.whatsapp.exchange')), {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        },
                        body: JSON.stringify({ code }),
                    }).catch(() => null);
                    const answer = response ? await response.json().catch(() => ({})) : {};

                    if (!response || !response.ok) {
                        fail(answer.message || text.failed);

                        return;
                    }

                    if (report({ token: answer.token, ...session })) {
                        status.textContent = text.done;
                        setTimeout(() => window.close(), 1500);
                    }
                };

                // Meta's signup window tells this page which WhatsApp account was connected.
                window.addEventListener('message', (event) => {
                    if (!event.origin.endsWith('facebook.com')) {
                        return;
                    }

                    let data;

                    try {
                        data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                    } catch {
                        return;
                    }

                    if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') {
                        return;
                    }

                    if (data.event === 'CANCEL') {
                        status.textContent = text.cancelled;
                    } else if (data.event === 'ERROR') {
                        fail(data.data?.error_message || text.failed);
                    } else if (String(data.event).startsWith('FINISH')) {
                        session = {
                            waba_id: data.data?.waba_id,
                            phone_number_id: data.data?.phone_number_id || null,
                            business_app: data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
                        };
                        finish();
                    }
                });

                document.getElementById('connect-start').addEventListener('click', () => {
                    status.textContent = '';
                    FB.login((response) => {
                        if (response.authResponse && response.authResponse.code) {
                            code = response.authResponse.code;
                            finish();
                        } else {
                            status.textContent = text.cancelled;
                        }
                    }, {
                        config_id: @js($configId),
                        response_type: 'code',
                        override_default_response_type: true,
                        extras: { setup: {}, featureType: 'whatsapp_business_app_onboarding' },
                    });
                });
            })();
        </script>
    @endif
</x-layouts.guest>
