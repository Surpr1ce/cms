/*
 * Suggestions while somebody types in a search box.
 *
 * An enhancement over a form that already works. Everything here attaches to
 * markup that submits and answers correctly with no JavaScript at all: the list
 * is created by this file rather than sitting empty in the template, so a
 * browser that never runs it is never told about a listbox that will not arrive.
 *
 * The pattern is the one assistive technology expects of a combobox — the input
 * owns the list through `aria-controls`, names the highlighted entry through
 * `aria-activedescendant`, and says whether the list is open through
 * `aria-expanded`. Moving through it with the arrow keys never moves focus off
 * the input, which is what lets somebody keep typing to narrow the list.
 *
 * No inline styles anywhere. The content security policy set by
 * SecurityHeadersSubscriber allows neither inline script nor inline style, so
 * everything visual is a class.
 *
 * **Closing forgets everything.** That is the rule the first version of this file
 * got wrong, in three related ways a review found: the last query was remembered
 * across a close, so pressing Escape and then clicking back into the box left it
 * silent for the text already in it; the suggestions array survived a close, so
 * the arrow keys still walked an invisible list and Enter navigated to a result
 * nobody had been shown; and a failed request cached its own failure, so one 429
 * silenced that query for good. `close()` now resets all of it.
 */

/** Long enough that typing a word is one request, short enough to feel immediate. */
const DEBOUNCE_MS = 180;

const LIST_CLASSES =
    'absolute inset-x-0 top-full z-40 mt-1 max-h-80 overflow-y-auto overflow-x-hidden ' +
    'rounded-md border border-rule bg-white py-1 shadow-lg';

const OPTION_CLASSES = 'flex cursor-pointer items-baseline gap-2 px-3 py-2 text-sm text-ink';

const OPTION_ACTIVE_CLASSES = ['bg-ink', 'text-white'];

/**
 * Every box currently enhanced, so that reverting can stop their timers and
 * abandon their requests rather than only removing their markup.
 */
const live = new Set();

let instances = 0;

/**
 * One search box, enhanced.
 */
class SearchSuggestions {
    constructor(form) {
        this.form = form;
        this.input = form.querySelector('[data-search-suggest-input]');
        this.endpoint = form.dataset.searchSuggestUrl;
        this.minimum = Number.parseInt(form.dataset.searchSuggestMinimum ?? '2', 10);

        this.identifier = `search-suggestions-${(instances += 1)}`;
        this.suggestions = [];
        this.activeIndex = -1;
        this.timer = null;
        this.inFlight = null;
        this.lastQuery = null;

        this.buildList();
        this.listen();
    }

    buildList() {
        this.list = document.createElement('ul');
        this.list.id = this.identifier;
        this.list.setAttribute('role', 'listbox');
        this.list.setAttribute('aria-label', 'Suggestions');
        this.list.className = LIST_CLASSES;
        this.list.hidden = true;
        // Marks everything this file built, so revertSearchBoxes() can find it
        // without keeping a registry that would outlive the elements.
        this.list.dataset.searchSuggestChrome = 'yes';

        /*
         * How many were found, for somebody who cannot see the list. Separate
         * from the list itself because a listbox's contents changing is not an
         * announcement — this is.
         */
        this.announcer = document.createElement('p');
        this.announcer.className = 'sr-only';
        this.announcer.setAttribute('role', 'status');
        this.announcer.dataset.searchSuggestChrome = 'yes';

        this.form.append(this.list, this.announcer);
        this.input.setAttribute('aria-controls', this.identifier);
    }

    listen() {
        this.input.addEventListener('input', () => this.scheduleFetch());
        this.input.addEventListener('keydown', (event) => this.onKeyDown(event));

        // Coming back to a box that already has something in it should offer the
        // suggestions again rather than waiting for another keystroke. Closing
        // forgets the last query, so this is a fresh request rather than a
        // replay.
        this.input.addEventListener('focus', () => this.scheduleFetch());

        /*
         * A click on an option would otherwise blur the input first and close
         * the list out from under the pointer. Refusing the blur on mousedown is
         * the standard repair, and it keeps the click on the option itself.
         */
        this.list.addEventListener('mousedown', (event) => event.preventDefault());
        this.list.addEventListener('click', (event) => {
            const option = event.target.closest('[data-url]');

            if (option) {
                this.go(option.dataset.url);
            }
        });

        this.input.addEventListener('blur', () => this.close());

        // Typing and then pressing the button should search, not follow whatever
        // happened to be highlighted.
        this.form.addEventListener('submit', () => this.close());
    }

    scheduleFetch() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.fetchSuggestions(), DEBOUNCE_MS);
    }

    async fetchSuggestions() {
        const query = this.input.value.trim();

        if (query.length < this.minimum) {
            this.render([]);

            return;
        }

        if (query === this.lastQuery) {
            return;
        }

        this.lastQuery = query;

        // The answer to a query nobody is waiting for is of no interest, and a
        // slow one arriving after a fast one would otherwise overwrite it.
        this.abandonRequest();
        this.inFlight = new AbortController();

        try {
            const response = await fetch(`${this.endpoint}?q=${encodeURIComponent(query)}`, {
                signal: this.inFlight.signal,
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                // Including 429. A box that quietly stops suggesting is the
                // right failure — there is nothing a reader can do about it, and
                // the form still searches. The query is forgotten so that trying
                // again once the allowance has recovered actually asks again.
                this.forget();

                return;
            }

            const payload = await response.json();

            this.render(Array.isArray(payload.suggestions) ? payload.suggestions : []);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.forget();
            }
        }
    }

    render(suggestions) {
        this.list.replaceChildren();

        if (suggestions.length === 0) {
            this.close();
            this.announcer.textContent = '';

            return;
        }

        this.suggestions = suggestions;
        this.activeIndex = -1;

        suggestions.forEach((suggestion, index) => {
            const option = document.createElement('li');
            option.id = `${this.identifier}-option-${index}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.className = OPTION_CLASSES;
            option.dataset.url = suggestion.url;

            const title = document.createElement('span');
            title.className = 'truncate';
            // textContent, never innerHTML. A title is somebody's words and this
            // is the one place on the site where they would be written into the
            // page by script rather than by Twig.
            title.textContent = suggestion.title;

            const kind = document.createElement('span');
            kind.className = 'ml-auto shrink-0 text-xs text-muted';
            kind.textContent = suggestion.kind === 'article' ? 'Article' : 'Page';

            option.append(title, kind);
            this.list.append(option);
        });

        this.open();
        this.announcer.textContent = `${suggestions.length} suggestion${suggestions.length === 1 ? '' : 's'}.`;
    }

    onKeyDown(event) {
        // `this.list.hidden` rather than the length of the array: after a close
        // the array is empty too, but reading the thing the user can actually
        // see is what stops the keys ever acting on an invisible list.
        const open = !this.list.hidden;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!open) {
                // Nothing to move through, and possibly something to fetch —
                // pressing down in a box with text in it should offer the list.
                this.scheduleFetch();

                return;
            }

            event.preventDefault();

            /*
             * Positions run from -1, meaning nothing highlighted, through
             * count - 1, and wrap round through -1 again. Shifting by one makes
             * the modulus do the wrapping for both directions: down from the
             * last entry returns to the box, and up from the box goes to the
             * last entry — which is what every native list does.
             */
            const count = this.suggestions.length;
            const step = event.key === 'ArrowDown' ? 1 : -1;

            this.highlight(((this.activeIndex + step + 1) + (count + 1)) % (count + 1) - 1);

            return;
        }

        if (event.key === 'Enter' && open && this.activeIndex >= 0) {
            event.preventDefault();
            this.go(this.suggestions[this.activeIndex].url);

            return;
        }

        if (event.key === 'Escape' && open) {
            // Taken before the browser's own handling: in a `type="search"`
            // field Escape empties the box, and somebody closing a dropdown did
            // not ask to lose what they had typed.
            event.preventDefault();
            this.close();
        }
    }

    highlight(index) {
        this.list.querySelectorAll('[role="option"]').forEach((option, position) => {
            const active = position === index;

            option.classList.toggle(OPTION_ACTIVE_CLASSES[0], active);
            option.classList.toggle(OPTION_ACTIVE_CLASSES[1], active);
            option.setAttribute('aria-selected', active ? 'true' : 'false');

            if (active) {
                option.scrollIntoView({ block: 'nearest' });
            }
        });

        this.activeIndex = index;

        if (index >= 0) {
            this.input.setAttribute('aria-activedescendant', `${this.identifier}-option-${index}`);
        } else {
            this.input.removeAttribute('aria-activedescendant');
        }
    }

    /**
     * Through Turbo where it is running, so that choosing a suggestion behaves
     * like clicking the same article in a listing — same cached snapshot, same
     * `turbo:before-cache` clean-up — rather than being the one full page load
     * on the site.
     */
    go(url) {
        this.close();

        if (window.Turbo?.visit) {
            window.Turbo.visit(url);

            return;
        }

        window.location.assign(url);
    }

    open() {
        this.list.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
    }

    /**
     * Closed, and with nothing remembered.
     *
     * Both halves matter. Leaving `suggestions` populated let the arrow keys
     * walk a list nobody could see; leaving `lastQuery` set made the box refuse
     * to ask again for text it had already asked about, so it never reopened.
     */
    close() {
        this.list.hidden = true;
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
        this.suggestions = [];
        this.activeIndex = -1;
        this.lastQuery = null;
    }

    /**
     * Nothing to show and nothing learned — used when a request failed rather
     * than when it honestly returned no matches.
     */
    forget() {
        this.render([]);
        this.lastQuery = null;
    }

    abandonRequest() {
        this.inFlight?.abort();
        this.inFlight = null;
    }

    /**
     * Stops everything still in flight. Removing the markup is not enough: a
     * debounce timer or a request started 180ms before a Turbo visit would
     * otherwise fire from a page that no longer exists, spending an allowance
     * the reader's next real search then lacks.
     */
    destroy() {
        window.clearTimeout(this.timer);
        this.abandonRequest();
    }
}

export function enhanceSearchBoxes() {
    document.querySelectorAll('form[data-search-suggest]').forEach((form) => {
        // Already done. Turbo fires turbo:load on the first load as well as
        // after every visit, and the fallback in app.js covers the case where it
        // never starts, so this runs more than once on the same markup.
        if (form.dataset.searchSuggestReady === 'yes') {
            return;
        }

        if (form.querySelector('[data-search-suggest-input]')) {
            live.add(new SearchSuggestions(form));
            form.dataset.searchSuggestReady = 'yes';
        }
    });
}

/**
 * Puts the markup back the way the server sent it, and stops what was running.
 *
 * Turbo stores a snapshot of the page before leaving it and restores that
 * snapshot on the way back. Without this, the snapshot would contain a list this
 * file had built — dead markup with no script attached to it, which would come
 * back as a dropdown that never opens and an `aria-controls` pointing at it.
 */
export function revertSearchBoxes() {
    live.forEach((instance) => instance.destroy());
    live.clear();

    document.querySelectorAll('[data-search-suggest-chrome]').forEach((element) => element.remove());

    document.querySelectorAll('form[data-search-suggest]').forEach((form) => {
        delete form.dataset.searchSuggestReady;

        const input = form.querySelector('[data-search-suggest-input]');

        input?.setAttribute('aria-expanded', 'false');
        input?.removeAttribute('aria-activedescendant');
        input?.removeAttribute('aria-controls');
    });
}
