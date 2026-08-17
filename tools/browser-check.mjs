/*
 * Drives a real browser over the DevTools protocol and checks the two things
 * `composer qa` cannot see: the search suggestions and the visual editor.
 *
 * **Why this exists.** Feature 018 added about six hundred lines of JavaScript,
 * and not one of the project's tests runs any of it. Every fault found in that
 * code was found here rather than by the suite — the editor being thrown away on
 * the next Turbo visit, the suggestion list refusing to reopen after Escape, the
 * arrow keys walking a list nobody could see, the Link button applying an
 * address the sanitiser would silently strip. A green suite said nothing about
 * any of them, twice.
 *
 * **It is not part of `composer qa`** and is not meant to be. It needs a running
 * site, a loaded database and a browser on the machine, which is three things
 * the quality gate deliberately does not require. Run it by hand before a
 * release, or after touching anything in `assets/`.
 *
 *     symfony serve -d
 *     node tools/browser-check.mjs
 *
 * The browser is found at CHROME, or set CHROME_PATH. The site is the first
 * argument, defaulting to http://127.0.0.1:8000.
 *
 * It signs in with the development fixture account, so it only works against a
 * development installation — which is the only place it should ever be pointed.
 */

import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CHROME = process.env.CHROME_PATH
    ?? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9333;
const SITE = (process.argv[2] ?? 'http://127.0.0.1:8000').replace(/\/$/, '');

/**
 * The development site is slow — the profiler runs on every request — so the
 * waits here are generous rather than tuned. A check that fails intermittently
 * is worse than one that takes a minute.
 *
 * SETTLE_MS is what a page is given *after* it says it is ready; the readiness
 * itself is waited for rather than slept through, in `goTo()`. A fixed sleep was
 * how this file reported five false failures against `php -S`, which serves one
 * request at a time: the module graph had not finished loading, so nothing had
 * been enhanced yet and the report blamed the enhancement.
 */
const READY_MS = 40_000;
const SETTLE_MS = 1500;
const REQUEST_MS = 4000;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let failures = 0;

function check(description, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);

    if (!ok) {
        failures += 1;
    }

    console.log(
        `${ok ? '  ok  ' : '  FAIL'}  ${description}`
        + (ok ? '' : `\n          expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`),
    );
}

// ---------------------------------------------------------------- plumbing

const profile = mkdtempSync(join(tmpdir(), 'cms-browser-check-'));

const chrome = spawn(CHROME, [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${profile}`,
    'about:blank',
], { stdio: 'ignore' });

chrome.on('error', (error) => {
    console.error(`Could not start a browser at ${CHROME}: ${error.message}`);
    console.error('Set CHROME_PATH to a Chrome or Edge executable.');
    process.exit(2);
});

async function pageTarget() {
    for (let attempt = 0; attempt < 40; attempt += 1) {
        try {
            const targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
            const page = targets.find((entry) => entry.type === 'page' && entry.webSocketDebuggerUrl);

            if (page) {
                return page.webSocketDebuggerUrl;
            }
        } catch {
            // Not listening yet.
        }

        await sleep(250);
    }

    throw new Error('The browser never offered a page to drive.');
}

const socket = new WebSocket(await pageTarget());
await new Promise((resolve) => socket.addEventListener('open', resolve));

let nextId = 0;
const pending = new Map();
const pageErrors = [];

socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);

    if (message.method === 'Runtime.exceptionThrown') {
        pageErrors.push(
            message.params.exceptionDetails.exception?.description
            ?? message.params.exceptionDetails.text,
        );
    }

    if (message.id && pending.has(message.id)) {
        pending.get(message.id)(message);
        pending.delete(message.id);
    }
});

function send(method, params = {}) {
    const id = (nextId += 1);

    return new Promise((resolve) => {
        pending.set(id, resolve);
        socket.send(JSON.stringify({ id, method, params }));
    });
}

async function evaluate(expression) {
    const message = await send('Runtime.evaluate', {
        expression,
        awaitPromise: true,
        returnByValue: true,
    });

    if (message.result?.exceptionDetails) {
        return `THREW: ${message.result.exceptionDetails.exception?.description ?? 'unknown'}`;
    }

    return message.result?.result?.value;
}

/**
 * Navigates, then waits for the page to have run its scripts rather than for a
 * fixed number of seconds.
 *
 * `window.Turbo` is the signal: it exists once `app.js` has been imported, and
 * `app.js` is what enhances the search boxes and the editor. Waiting for the
 * document alone is not enough — `readyState` reaches `complete` while a module
 * graph is still being fetched, which on a single-request-at-a-time server is
 * several seconds after the HTML arrived.
 */
async function goTo(path) {
    await send('Page.navigate', { url: SITE + path });

    for (let waited = 0; waited < READY_MS; waited += 250) {
        const ready = await evaluate(
            `document.readyState === 'complete' && typeof window.Turbo === 'object'`,
        );

        if (true === ready) {
            break;
        }

        await sleep(250);
    }

    // Whatever runs on turbo:load has not necessarily run when Turbo appears.
    await sleep(SETTLE_MS);
}

await send('Page.enable');
await send('Runtime.enable');

// ------------------------------------------------- the search suggestions

console.log('\nSearch suggestions');

await goTo('/');

check('the box is enhanced', await evaluate(
    `document.querySelectorAll('[role="listbox"]').length`,
), 1);

// A word from the development dataset. If this ever finds nothing, load the
// fixtures rather than blaming the JavaScript.
await evaluate(`
    (() => {
        const input = document.querySelector('[data-search-suggest-input]');
        input.focus();
        input.value = 'doctrine';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
    })()
`);
await sleep(REQUEST_MS);

check('typing opens the list', await evaluate(
    `document.querySelector('[data-search-suggest-input]').getAttribute('aria-expanded')`,
), 'true');

check('something was suggested', await evaluate(
    `document.querySelectorAll('[role="option"]').length > 0`,
), true);

check('and it was announced', await evaluate(
    `/suggestion/.test(document.querySelector('[role="status"]')?.textContent ?? '')`,
), true);

await evaluate(`
    (() => {
        document.querySelector('[data-search-suggest-input]')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        return true;
    })()
`);
await sleep(200);

check('the down arrow highlights an entry', await evaluate(
    `document.querySelector('[data-search-suggest-input]').getAttribute('aria-activedescendant') !== null`,
), true);

check('and focus stays in the box', await evaluate(
    `document.activeElement === document.querySelector('[data-search-suggest-input]')`,
), true);

await evaluate(`
    (() => {
        document.querySelector('[data-search-suggest-input]')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        return true;
    })()
`);
await sleep(200);

check('escape closes the list', await evaluate(
    `document.querySelector('[data-search-suggest-input]').getAttribute('aria-expanded')`,
), 'false');

check('and keeps what was typed', await evaluate(
    `document.querySelector('[data-search-suggest-input]').value`,
), 'doctrine');

// The three defects a review found in the first version, each pinned here.
await evaluate(`
    (() => {
        document.querySelector('[data-search-suggest-input]')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        return true;
    })()
`);
await sleep(200);

check('the arrows do not walk a closed list', await evaluate(
    `document.querySelector('[data-search-suggest-input]').getAttribute('aria-activedescendant') === null`,
), true);

await evaluate(`
    (() => {
        document.querySelector('[data-search-suggest-input]')
            .dispatchEvent(new Event('focus', { bubbles: true }));
        return true;
    })()
`);
await sleep(REQUEST_MS);

check('coming back offers the same text again', await evaluate(
    `document.querySelector('[data-search-suggest-input]').getAttribute('aria-expanded')`,
), 'true');

// --------------------------------------------------------- the editor

console.log('\nVisual editor');

await goTo('/login');
await evaluate(`
    (() => {
        document.querySelector('input[name="_username"]').value = 'admin@example.com';
        document.querySelector('input[name="_password"]').value = 'development-only';
        document.querySelector('form[action="/login"]').submit();
        return true;
    })()
`);
await sleep(SETTLE_MS);

await goTo('/admin/articles/new');

check('the toolbar is built', await evaluate(
    `document.querySelectorAll('[data-markup-editor-chrome][role="toolbar"]').length`,
), 1);

check('the text area is hidden behind it', await evaluate(
    `document.querySelector('textarea[data-markup-editor]').classList.contains('hidden')`,
), true);

check('the toolbar is one tab stop', await evaluate(`
    (() => {
        const buttons = Array.from(document.querySelectorAll('[role="toolbar"] button'));
        return buttons.filter((b) => b.tabIndex === 0).length;
    })()
`), 1);

await evaluate(`
    (() => {
        const surface = document.querySelector('[contenteditable="true"]');
        surface.focus();
        document.execCommand('insertText', false, 'A heading');
        Array.from(document.querySelectorAll('[role="toolbar"] button'))
            .find((b) => b.textContent === 'H2').click();
        return true;
    })()
`);
await sleep(300);

check('a command reaches the text area', await evaluate(
    `document.querySelector('textarea[data-markup-editor]').value`,
), '<h2>A heading</h2>');

/**
 * The Link button, which is the one control that can produce an address the
 * server would refuse. Every answer here is what the sanitiser will keep.
 */
async function linkWith(typed) {
    return evaluate(`
        (() => {
            const surface = document.querySelector('[contenteditable="true"]');
            surface.innerHTML = '<p>anchor</p>';
            surface.focus();

            const range = document.createRange();
            range.selectNodeContents(surface.querySelector('p'));
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);

            const realPrompt = window.prompt;
            const realAlert = window.alert;
            let refused = false;
            window.prompt = () => ${JSON.stringify(typed)};
            window.alert = () => { refused = true; };

            Array.from(document.querySelectorAll('[role="toolbar"] button'))
                .find((b) => b.textContent === 'Link').click();

            window.prompt = realPrompt;
            window.alert = realAlert;

            const anchor = surface.querySelector('a');
            return refused ? 'refused' : (anchor?.getAttribute('href') ?? 'none');
        })()
    `);
}

check('a scheme that executes is refused', await linkWith('javascript:alert(1)'), 'refused');
check('an off-site protocol-relative address is refused', await linkWith('//evil.example'), 'refused');
check('a bare domain becomes https', await linkWith('example.com'), 'https://example.com');
check('a path on this site is kept', await linkWith('/about-us'), '/about-us');
check('an email address becomes mailto', await linkWith('someone@example.com'), 'mailto:someone@example.com');

// Turbo replaces the body on every visit and caches a snapshot before leaving,
// so an editor built once and never rebuilt is the failure this pins.
await goTo('/admin/articles');
await goTo('/admin/articles/new');

check('the editor survives a Turbo visit, exactly once', await evaluate(
    `document.querySelectorAll('[data-markup-editor-chrome][role="toolbar"]').length`,
), 1);

// ------------------------------------------------------------------ result

console.log('');

if (pageErrors.length > 0) {
    failures += pageErrors.length;
    console.log(`  FAIL  the page threw ${pageErrors.length} error(s):`);
    pageErrors.forEach((error) => console.log(`          ${error}`));
}

console.log(failures === 0 ? '\nAll browser checks passed.\n' : `\n${failures} browser check(s) failed.\n`);

socket.close();
chrome.kill();
process.exit(failures === 0 ? 0 : 1);
