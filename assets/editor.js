/*
 * A visual editor for the one field on this site that holds markup.
 *
 * Hand-written, and small, for the same reason the administration screens are:
 * an editor from a package brings a build step this project does not have, a
 * stylesheet it injects at runtime that the content security policy forbids, and
 * a vocabulary of its own that has to be reconciled with what the server will
 * actually store.
 *
 * **It carries no authority.** Everything it produces goes into the same text
 * area the field has always been, is submitted as the same string, and is
 * sanitised on the way in by ContentSanitiser exactly as before. An article
 * written here is indistinguishable from one typed as markup. That is the
 * property docs/adr/0010 depends on and this file was written not to disturb —
 * see docs/adr/0014.
 *
 * **It is an enhancement over a control that works.** With no JavaScript the
 * field is the text area it has always been. Nothing here is required to save an
 * article; the toolbar is a convenience for somebody who does not write HTML.
 *
 * The commands offered are exactly the ones whose output survives the
 * allow-list. Adding a button means adding an element to ContentSanitiser first,
 * and the unit test asserting that everything below survives sanitising is what
 * stops the two drifting apart.
 */

const SURFACE_CLASSES =
    'prose max-w-none min-h-80 rounded-b border border-t-0 border-rule bg-white px-4 py-3 ' +
    'text-ink outline-none focus:border-accent';

const TOOLBAR_CLASSES =
    'flex flex-wrap items-center gap-1 rounded-t border border-rule bg-rule/30 px-2 py-1.5';

const BUTTON_CLASSES =
    'rounded px-2 py-1 text-sm text-ink hover:bg-white aria-pressed:bg-ink aria-pressed:text-white';

const SEPARATOR_CLASSES = 'mx-1 h-5 w-px bg-rule';

/**
 * The address the sanitiser will keep, or null when there is none.
 *
 * Deliberately narrow, and deliberately the same three schemes
 * ContentSanitiser names plus the scheme-less form it now allows. It is not a
 * second copy of the allow-list — that governs *elements*, and this governs one
 * attribute of one of them — but the two do have to agree about link schemes,
 * which is why both say so in a comment naming the other.
 */
function normaliseAddress(typed) {
    if ('' === typed) {
        return null;
    }

    // A path on this site, or an anchor within the page. Kept as typed: the
    // sanitiser allows an address with no scheme precisely so these work.
    if (typed.startsWith('/') || typed.startsWith('#')) {
        // Not `//host`, which looks internal and is not.
        return typed.startsWith('//') ? null : typed;
    }

    if (/^(https?:|mailto:)/i.test(typed)) {
        return typed;
    }

    // Something with a scheme this application will not keep — javascript:,
    // data:, ftp: and anything else. Refused here so it is refused visibly.
    if (/^[a-z][a-z0-9+.-]*:/i.test(typed)) {
        return null;
    }

    // An email address typed on its own.
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(typed)) {
        return `mailto:${typed}`;
    }

    // A bare domain. https rather than http, because a site that only answers
    // on http will redirect and a site that only answers on https will not.
    return `https://${typed}`;
}

/**
 * What the toolbar offers.
 *
 * `block` commands ask for a block name; `inline` ones toggle. `state` is the
 * query the button uses to say whether it is currently on — omitted where the
 * browser has no answer for it, in which case the button simply never looks
 * pressed rather than looking wrong.
 */
const COMMANDS = [
    { label: 'H2', title: 'Heading', command: 'formatBlock', value: '<h2>', state: 'h2' },
    { label: 'H3', title: 'Subheading', command: 'formatBlock', value: '<h3>', state: 'h3' },
    { separator: true },
    { label: 'B', title: 'Bold', command: 'bold', state: 'bold', classes: 'font-bold' },
    { label: 'I', title: 'Italic', command: 'italic', state: 'italic', classes: 'italic' },
    { separator: true },
    { label: 'List', title: 'Bulleted list', command: 'insertUnorderedList', state: 'insertUnorderedList' },
    { label: '1. List', title: 'Numbered list', command: 'insertOrderedList', state: 'insertOrderedList' },
    { separator: true },
    { label: 'Quote', title: 'Quotation', command: 'formatBlock', value: '<blockquote>', state: 'blockquote' },
    { label: 'Code', title: 'Code block', command: 'formatBlock', value: '<pre>', state: 'pre' },
    { separator: true },
    { label: 'Link', title: 'Add a link', command: 'link' },
    { label: 'Clear', title: 'Remove formatting', command: 'removeFormat' },
];

class MarkupEditor {
    constructor(textarea) {
        this.textarea = textarea;
        this.visual = true;

        this.build();
        this.listen();

        /*
         * Paragraphs rather than divs when somebody presses Enter. `div` is not
         * in the allow-list, so the sanitiser would unwrap it and the article
         * would arrive as one long run of text with its paragraphing gone.
         *
         * Both of these are document-wide and set once per editor, which is
         * harmless: they are idempotent and there is at most one of these
         * screens open at a time.
         */
        document.execCommand('defaultParagraphSeparator', false, 'p');
        // `false` means "use tags, not inline styles" — <b> rather than
        // <span style="font-weight:bold">, which the allow-list would strip back
        // to plain text.
        document.execCommand('styleWithCSS', false, false);
    }

    build() {
        this.toolbar = document.createElement('div');
        this.toolbar.className = TOOLBAR_CLASSES;
        this.toolbar.setAttribute('role', 'toolbar');
        this.toolbar.setAttribute('aria-label', 'Formatting');
        // Marks what this file built. The text area is not wrapped in anything,
        // so reverting is a matter of removing these two and unhiding it —
        // see revertMarkupFields() for why that has to be possible.
        this.toolbar.dataset.markupEditorChrome = 'yes';

        this.buttons = [];

        COMMANDS.forEach((entry) => {
            if (entry.separator) {
                const separator = document.createElement('span');
                separator.className = SEPARATOR_CLASSES;
                separator.setAttribute('aria-hidden', 'true');
                this.toolbar.append(separator);

                return;
            }

            const button = document.createElement('button');
            // Without this a button inside a form submits it, and the first
            // click on "Bold" would save the article.
            button.type = 'button';
            button.className = entry.classes ? `${BUTTON_CLASSES} ${entry.classes}` : BUTTON_CLASSES;
            button.textContent = entry.label;
            button.title = entry.title;
            button.setAttribute('aria-label', entry.title);

            if (entry.state) {
                button.setAttribute('aria-pressed', 'false');
            }

            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => this.run(entry));

            this.buttons.push({ entry, button });
            this.toolbar.append(button);
        });

        this.toggle = document.createElement('button');
        this.toggle.type = 'button';
        this.toggle.className = `${BUTTON_CLASSES} ml-auto`;
        this.toggle.textContent = 'Markup';
        this.toggle.title = 'Edit the markup directly';
        this.toggle.setAttribute('aria-pressed', 'false');
        this.toggle.addEventListener('click', () => this.toggleView());
        this.toolbar.append(this.toggle);

        this.makeToolbarOneTabStop();

        this.surface = document.createElement('div');
        this.surface.className = SURFACE_CLASSES;
        this.surface.contentEditable = 'true';
        this.surface.setAttribute('role', 'textbox');
        this.surface.setAttribute('aria-multiline', 'true');
        this.surface.setAttribute('aria-label', this.labelText());
        this.surface.dataset.markupEditorChrome = 'yes';
        this.surface.innerHTML = this.textarea.value;

        // Inserted as siblings rather than by wrapping the text area in a
        // container. A wrapper would have to be unwrapped again to put the page
        // back the way the server sent it, and unwrapping is the operation that
        // goes wrong.
        this.textarea.before(this.toolbar, this.surface);
        this.textarea.classList.add('hidden', 'rounded-t-none');
    }

    /**
     * One tab stop for the whole toolbar, with the arrow keys moving inside it.
     *
     * This is what `role="toolbar"` promises and the first version did not keep:
     * every button was a tab stop, so a keyboard user pressed Tab thirteen times
     * to get from the toolbar into the field they came to write in, and the
     * arrow keys — which assistive technology announces as the way through a
     * toolbar — did nothing.
     */
    makeToolbarOneTabStop() {
        this.focusable = [...this.buttons.map(({ button }) => button), this.toggle];

        this.focusable.forEach((button, index) => {
            button.tabIndex = 0 === index ? 0 : -1;
        });

        this.toolbar.addEventListener('keydown', (event) => {
            const step = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' }[event.key];

            if (undefined === step) {
                return;
            }

            event.preventDefault();

            const from = this.focusable.indexOf(document.activeElement);
            const count = this.focusable.length;

            const to = 'first' === step
                ? 0
                : 'last' === step
                    ? count - 1
                    : (from + step + count) % count;

            this.focusable.forEach((button, index) => {
                button.tabIndex = index === to ? 0 : -1;
            });

            this.focusable[to].focus();
        });
    }

    /**
     * What the field is called, taken from the label the form already renders
     * rather than hard-coded — this file does not know whether it is enhancing
     * an article or a page.
     */
    labelText() {
        const label = this.textarea.id
            ? document.querySelector(`label[for="${this.textarea.id}"]`)
            : null;

        return label?.textContent?.trim() || 'Body';
    }

    listen() {
        this.surface.addEventListener('input', () => this.syncToTextarea());
        this.surface.addEventListener('paste', (event) => this.onPaste(event));
        this.surface.addEventListener('keyup', () => this.refreshButtons());
        this.surface.addEventListener('mouseup', () => this.refreshButtons());

        // Whatever the browser was showing when the form went is what gets
        // stored. Without this, a save made without touching the surface again
        // after the last command would store the previous value.
        this.textarea.form?.addEventListener('submit', () => {
            if (this.visual) {
                this.syncToTextarea();
            }
        });
    }

    /**
     * Pasting arrives as text.
     *
     * A paste from a word processor carries a document's worth of markup that
     * the allow-list mostly strips, so keeping it would show somebody formatting
     * that silently disappears when they save. Cleaning it in the browser would
     * mean writing the allow-list a second time, in a second language, and
     * having the two drift.
     *
     * Text is the honest option: what you see after pasting is what will be
     * stored. Formatting is then applied with the toolbar.
     */
    onPaste(event) {
        event.preventDefault();

        const text = event.clipboardData?.getData('text/plain') ?? '';

        document.execCommand('insertText', false, text);
    }

    run(entry) {
        this.surface.focus();

        if (entry.command === 'link') {
            this.link();
        } else {
            document.execCommand(entry.command, false, entry.value ?? null);
        }

        this.syncToTextarea();
        this.refreshButtons();
    }

    /**
     * A link, with the address checked before it is applied.
     *
     * The first version handed whatever was typed straight to `createLink`. The
     * sanitiser keeps only http, https, mailto and addresses with no scheme at
     * all — so `javascript:…`, `ftp://…` and the like arrived on screen as
     * working links and were silently stripped at the moment of saving. That is
     * exactly the "formatting that vanishes on save" this editor was designed
     * not to do, and a review found it in the one button that could do it.
     *
     * A bare `example.com` is the common case and is not refused: it is given
     * `https://`, because somebody typing a domain means a web address and
     * leaving it alone would make it a relative link to a page of that name.
     */
    link() {
        const typed = window.prompt('Address to link to');

        if (!typed) {
            return;
        }

        const href = normaliseAddress(typed.trim());

        if (href === null) {
            window.alert(
                'That address cannot be linked to. Use a web address, an email address, '
                + 'or a path on this site such as /about-us.',
            );

            return;
        }

        document.execCommand('createLink', false, href);
    }

    refreshButtons() {
        this.buttons.forEach(({ entry, button }) => {
            if (!entry.state) {
                return;
            }

            let pressed = false;

            try {
                pressed = entry.command === 'formatBlock'
                    ? document.queryCommandValue('formatBlock').toLowerCase() === entry.state
                    : document.queryCommandState(entry.state);
            } catch {
                // Browsers are allowed to refuse these, and a button that never
                // looks pressed is a smaller problem than one that throws on
                // every keystroke.
                pressed = false;
            }

            button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        });
    }

    syncToTextarea() {
        this.textarea.value = this.surface.innerHTML;
    }

    toggleView() {
        this.visual = !this.visual;

        if (this.visual) {
            this.surface.innerHTML = this.textarea.value;
        } else {
            this.syncToTextarea();
        }

        this.surface.classList.toggle('hidden', !this.visual);
        this.textarea.classList.toggle('hidden', this.visual);

        this.buttons.forEach(({ button }) => {
            button.disabled = !this.visual;
        });

        this.toggle.setAttribute('aria-pressed', this.visual ? 'false' : 'true');
        (this.visual ? this.surface : this.textarea).focus();
    }
}

export function enhanceMarkupFields() {
    document.querySelectorAll('textarea[data-markup-editor]').forEach((textarea) => {
        // Turbo fires turbo:load on the first load as well as after every visit,
        // and app.js has a fallback for when it never starts, so this runs more
        // than once on the same field. Without the marker each run would add
        // another toolbar.
        if (textarea.dataset.markupEditorReady === 'yes') {
            return;
        }

        new MarkupEditor(textarea);
        textarea.dataset.markupEditorReady = 'yes';
    });
}

/**
 * Puts the field back the way the server sent it.
 *
 * Turbo stores a snapshot of the page before leaving it. Without this, the
 * snapshot would hold a toolbar with no script behind it, a `contenteditable`
 * that syncs nowhere, and a hidden text area — so going back to a half-written
 * article would show an editor where every button does nothing.
 */
export function revertMarkupFields() {
    document.querySelectorAll('[data-markup-editor-chrome]').forEach((element) => element.remove());

    document.querySelectorAll('textarea[data-markup-editor]').forEach((textarea) => {
        textarea.classList.remove('hidden', 'rounded-t-none');
        delete textarea.dataset.markupEditorReady;
    });
}
