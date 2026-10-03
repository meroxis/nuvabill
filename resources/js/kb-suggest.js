/**
 * New ticket form: while the client types the subject, show knowledge base articles that may
 * answer the question, so they can find the answer without waiting for a reply.
 */
export function kbSuggest(config) {
    return {
        articles: [],
        timer: null,
        // The subject whose articles are on screen.
        lastQuery: '',
        // Counts lookups, so an answer that comes back after the subject changed again is ignored.
        seq: 0,

        lookup(value) {
            clearTimeout(this.timer);
            const id = ++this.seq;
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

                try {
                    const response = await fetch(`${config.url}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
                    const data = response.ok ? await response.json() : null;

                    if (id !== this.seq) {
                        return;
                    }

                    this.articles = data && Array.isArray(data.articles) ? data.articles.slice(0, 5) : [];
                    // A failed lookup is tried again the next time.
                    this.lastQuery = data ? query : '';
                } catch {
                    if (id === this.seq) {
                        this.articles = [];
                        this.lastQuery = '';
                    }
                }
            }, 400);
        },
    };
}
