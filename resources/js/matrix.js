/**
 * atannex/matrix browser client. No dependencies.
 *
 *   import { Matrix } from './vendor/matrix.js';
 *
 *   const post = new Matrix(token);             // token: $post->matrixToken()
 *   post.trackView();                           // once the item is on screen
 *   post.trackReading();                        // engaged reading time
 *   await post.react('like');                   // → { stats, reactions, state }
 *   await post.rate(5);
 *   await post.comment('Great read!', { replyTo: 12 });
 *
 *   Matrix.autoTrack();                         // every [data-matrix-token]
 *
 * Every write resolves to the item's fresh totals and the visitor's state.
 * Errors reject with { status, message, errors }.
 */

const BASE = (document.querySelector('meta[name="matrix-base"]')?.content || '/matrix').replace(/\/$/, '');

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function beacon(path, data) {
    const body = new FormData();
    Object.entries(data).forEach(([key, value]) => value !== undefined && value !== null && body.append(key, value));
    body.append('_token', csrf());

    if (navigator.sendBeacon && navigator.sendBeacon(`${BASE}/beacon/${path}`, body)) {
        return;
    }

    fetch(`${BASE}/beacon/${path}`, { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(() => {});
}

export class Matrix {
    constructor(token) {
        if (!token) {
            throw new Error('Matrix needs the item\'s tracking token.');
        }

        this.token = token;
        this.viewed = false;
    }

    /* ------------------------------------------------------------------ */
    /* Tracking                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Record a view once the element is at least half visible for a second.
     */
    trackView(element = null) {
        if (this.viewed) {
            return;
        }

        const send = () => {
            if (!this.viewed) {
                this.viewed = true;
                beacon('view', { token: this.token });
            }
        };

        if (!element || !('IntersectionObserver' in window)) {
            send();
            return;
        }

        let timer = null;
        const observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting) {
                timer = setTimeout(() => { send(); observer.disconnect(); }, 1000);
            } else {
                clearTimeout(timer);
            }
        }, { threshold: 0.5 });

        observer.observe(element);
    }

    /**
     * Count seconds while the tab is visible and the reader was active in the
     * last 30 s; report every 15 s and when the page is hidden.
     */
    trackReading({ idleAfter = 30, every = 15 } = {}) {
        let seconds = 0;
        let first = true;
        let lastActivity = Date.now();

        const active = () => { lastActivity = Date.now(); };
        ['scroll', 'mousemove', 'keydown', 'touchstart'].forEach((e) => addEventListener(e, active, { passive: true }));

        const flush = () => {
            if (seconds > 0) {
                beacon('read', { token: this.token, seconds, first: first ? 1 : 0 });
                seconds = 0;
                first = false;
            }
        };

        setInterval(() => {
            if (document.visibilityState === 'visible' && Date.now() - lastActivity < idleAfter * 1000) {
                seconds++;
            }

            if (seconds >= every) {
                flush();
            }
        }, 1000);

        addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && flush());
        addEventListener('pagehide', flush);
    }

    trackClick({ placement = null, page = location.pathname } = {}) {
        beacon('click', { token: this.token, placement, page });
    }

    /* ------------------------------------------------------------------ */
    /* API                                                                 */
    /* ------------------------------------------------------------------ */

    async request(method, path = '', data = null) {
        const response = await fetch(`${BASE}/${this.token}${path}`, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: data && method !== 'GET' ? JSON.stringify(data) : undefined,
        });

        const json = response.status === 204 ? {} : await response.json().catch(() => ({}));

        if (!response.ok) {
            throw { status: response.status, message: json.message || response.statusText, errors: json.errors || {} };
        }

        this.dispatch(json);

        return json;
    }

    dispatch(detail) {
        document.dispatchEvent(new CustomEvent('matrix:updated', { detail: { token: this.token, ...detail } }));
    }

    state() { return this.request('GET'); }

    react(type, { toggle = true } = {}) { return this.request('POST', '/reactions', { type, toggle }); }
    unreact() { return this.request('DELETE', '/reactions'); }
    like() { return this.react('like'); }
    dislike() { return this.react('dislike'); }

    rate(value, { dimension = 'overall', comment = null } = {}) { return this.request('POST', '/ratings', { value, dimension, comment }); }
    unrate(dimension = 'overall') { return this.request('DELETE', '/ratings', { dimension }); }

    reviews({ perPage = 10, sort = 'helpful' } = {}) { return this.request('GET', `/reviews?per_page=${perPage}&sort=${sort}`); }
    review(body, { rating = null, title = null, guestName = null, guestEmail = null } = {}) {
        return this.request('POST', '/reviews', { body, rating, title, guest_name: guestName, guest_email: guestEmail });
    }
    unreview() { return this.request('DELETE', '/reviews'); }

    comments({ limit = 20, offset = 0 } = {}) { return this.request('GET', `/comments?limit=${limit}&offset=${offset}`); }
    comment(body, { replyTo = null, guestName = null, guestEmail = null } = {}) {
        return this.request('POST', '/comments', { body, reply_to: replyTo, guest_name: guestName, guest_email: guestEmail });
    }
    editComment(id, body) { return this.request('PATCH', `/comments/${id}`, { body }); }
    deleteComment(id) { return this.request('DELETE', `/comments/${id}`); }
    likeComment(id) { return this.request('POST', `/comments/${id}/like`); }

    share(platform) { return this.request('POST', '/shares', { platform }); }

    bookmark(collection = 'default', note = null) { return this.request('POST', '/bookmark', { collection, note }); }
    unbookmark(collection = null) { return this.request('DELETE', '/bookmark', { collection }); }

    follow(notify = true) { return this.request('POST', '/follow', { notify }); }
    unfollow() { return this.request('DELETE', '/follow'); }

    report(reason, details = null) { return this.request('POST', '/reports', { reason, details }); }

    /* ------------------------------------------------------------------ */
    /* Declarative                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * <article data-matrix-token="…" data-matrix-track="view read">
     *   <button data-matrix-action="like">👍 <span data-matrix-stat="reactions.like"></span></button>
     *   <button data-matrix-action="share" data-matrix-arg="whatsapp">Share</button>
     * </article>
     */
    static autoTrack(root = document) {
        root.querySelectorAll('[data-matrix-token]').forEach((element) => {
            const matrix = new Matrix(element.dataset.matrixToken);
            const track = (element.dataset.matrixTrack || 'view').split(/\s+/);

            track.includes('view') && matrix.trackView(element);
            track.includes('read') && matrix.trackReading();

            element.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-matrix-action]');

                if (!button || typeof matrix[button.dataset.matrixAction] !== 'function') {
                    return;
                }

                event.preventDefault();
                button.disabled = true;

                try {
                    const result = await matrix[button.dataset.matrixAction](...(button.dataset.matrixArg ? [button.dataset.matrixArg] : []));
                    Matrix.render(element, result);
                } catch (error) {
                    element.dispatchEvent(new CustomEvent('matrix:error', { detail: error, bubbles: true }));
                } finally {
                    button.disabled = false;
                }
            });
        });
    }

    static render(element, { stats = {}, state = {} } = {}) {
        element.querySelectorAll('[data-matrix-stat]').forEach((node) => {
            const value = stats[node.dataset.matrixStat];
            node.textContent = typeof value === 'number' ? value.toLocaleString() : '0';
        });

        element.querySelectorAll('[data-matrix-action="like"], [data-matrix-action="dislike"], [data-matrix-action="react"]').forEach((node) => {
            const type = node.dataset.matrixArg || node.dataset.matrixAction;
            node.setAttribute('aria-pressed', String(state.reaction === type));
        });
    }
}

export default Matrix;
