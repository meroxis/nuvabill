/**
 * Settings → Chat apps: "Connect with a QR code" opens Meta's WhatsApp signup on the Nuvabill store
 * in a window. When the owner is done, that window sends this page the owner's WhatsApp access
 * token with a postMessage, and this page hands it to Nuvabill to finish the setup.
 *
 * Usage: x-data="whatsappConnect(@js($config))" with {url, origin, state, endpoint, failed, blocked}.
 */
export function whatsappConnect(config) {
    return {
        busy: false,
        error: '',
        popup: null,

        init() {
            window.addEventListener('message', (event) => this.receive(event));
        },

        open() {
            this.error = '';
            this.popup = window.open(config.url, 'nuvabill-whatsapp', 'width=640,height=780');

            if (!this.popup) {
                this.error = config.blocked;
            }
        },

        async receive(event) {
            const data = event.data;

            if (event.origin !== config.origin || !data || data.source !== 'nuvabill-whatsapp' || data.state !== config.state) {
                return;
            }

            if (data.error) {
                this.error = data.error;

                return;
            }

            this.busy = true;

            try {
                const response = await fetch(config.endpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        state: data.state,
                        token: data.token,
                        waba_id: data.waba_id,
                        phone_number_id: data.phone_number_id || null,
                        business_app: Boolean(data.business_app),
                    }),
                });
                const answer = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(answer.message || config.failed);
                }

                window.location.reload();
            } catch (exception) {
                this.error = exception.message || config.failed;
                this.busy = false;
            }
        },
    };
}
