/**
 * AI help in the admin area. Every request goes to Nuvabill, which asks Claude and answers with
 * JSON; errors come back as { message }. Staff always read and send replies themselves.
 *
 * Usage: x-data="ticketAi(@js($config))" on the ticket page, x-data="aiMessage(@js($config))" on one
 * client message, and x-data="productAi(@js($config))" on the product form.
 */
async function askNuvabill(url, body, failed) {
    let response;

    try {
        response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(body),
        });
    } catch {
        throw new Error(failed);
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(data.message || failed);
    }

    return data;
}

const sameText = (a, b) => a.replace(/\r\n/g, '\n').trim() === b.replace(/\r\n/g, '\n').trim();

export function ticketAi(config) {
    return {
        reply: config.reply ?? '',
        summary: config.summary ?? null,
        stale: config.stale ?? false,
        sendTranslated: config.translate ?? false,
        instruction: '',
        translation: '',
        translatedFrom: '',
        busy: '',
        error: '',
        notice: '',
        drafted: false,

        async run(kind, url, body) {
            this.busy = kind;
            this.error = '';
            this.notice = '';

            try {
                const data = await askNuvabill(url, body, config.failed);
                this.notice = data.notice || '';

                return data;
            } catch (exception) {
                this.error = exception.message;

                return null;
            } finally {
                this.busy = '';
            }
        },

        async draft(tone = null) {
            const data = await this.run('draft', config.urls.draft, {
                tone,
                current: tone ? this.reply : null,
                instruction: tone ? null : this.instruction,
            });

            if (data) {
                this.reply = data.draft;
                this.drafted = true;
                this.$nextTick(() => this.$refs.reply?.focus());
            }
        },

        async summarize() {
            const data = await this.run('summary', config.urls.summary, {});

            if (data) {
                this.summary = data;
                this.stale = false;
            }
        },

        fresh() {
            return this.translation !== '' && sameText(this.translatedFrom, this.reply);
        },

        async translate() {
            const text = this.reply;
            const data = await this.run('translate', config.urls.translate, { text });

            if (data) {
                this.translation = data.text;
                this.translatedFrom = text;
            }
        },

        /**
         * Sending in the client's language takes two presses: the first shows the translation,
         * the second sends exactly that.
         */
        async send(event) {
            if (!this.sendTranslated || this.fresh() || this.reply.trim() === '') {
                return;
            }

            event.preventDefault();
            await this.translate();
        },
    };
}

export function aiMessage(config) {
    return {
        translation: '',
        message: '',
        busy: false,

        async translate() {
            this.busy = true;
            this.message = '';

            try {
                const data = await askNuvabill(config.url, {}, config.failed);
                this.translation = data.translation || '';
                this.message = data.notice || '';
            } catch (exception) {
                this.message = exception.message;
            } finally {
                this.busy = false;
            }
        },
    };
}

export function productAi(config) {
    return {
        busy: false,
        error: '',
        notice: '',
        instruction: '',

        async write() {
            const form = this.$root.closest('form');
            const value = (name) => form.querySelector(`[name="${name}"]`)?.value ?? '';

            this.busy = true;
            this.error = '';
            this.notice = '';

            try {
                const data = await askNuvabill(config.url, {
                    name: value('name'),
                    product_group_id: value('product_group_id') || null,
                    type: value('type'),
                    description: value('description'),
                    instruction: this.instruction || null,
                    product: config.product,
                }, config.failed);

                const description = form.querySelector('[name="description"]');

                if (description) {
                    description.value = data.description;
                    description.dispatchEvent(new Event('input', { bubbles: true }));
                }

                window.dispatchEvent(new CustomEvent('ai-search-text', { detail: { title: data.seo_title, description: data.seo_description } }));
                this.notice = data.notice || config.done;
            } catch (exception) {
                this.error = exception.message;
            } finally {
                this.busy = false;
            }
        },
    };
}
