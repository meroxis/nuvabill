/**
 * New ticket form: while the client types the subject, show knowledge base articles that may
 * answer the question, so they can find the answer without waiting for a reply.
 */
export function kbSuggest(config) {
    return {
        articles: [],
        timer: null,
        lastQuery: '',

        lookup(value) {
            clearTimeout(this.timer);
            const query = String(value || '').trim();

            if (query.length < 3) {
                this.articles = [];
                this.lastQuery = '';

                return;
            }

            this.timer = setTimeout(async () => {
                if (query === this.lastQuery) {
                    return;
                }

                this.lastQuery = query;

                try {
                    const response = await fetch(`${config.url}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });

                    if (!response.ok) {
                        return;
                    }

                    const data = await response.json();
                    this.articles = Array.isArray(data.articles) ? data.articles.slice(0, 5) : [];
                } catch {
                    this.articles = [];
                }
            }, 400);
        },
    };
}
