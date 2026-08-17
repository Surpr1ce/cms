/*
 * The two small behaviours this site has, moved out of attributes.
 *
 * Both used to be inline handlers — `onsubmit="return confirm(...)"` on the
 * delete forms and `onerror="this.style.display='none'"` on lead images. Feature
 * 008's content security policy forbids inline script, and an event handler
 * attribute is inline script: both had silently stopped working, and nothing
 * noticed because neither is something a functional test can see.
 *
 * Here they are ordinary module code, loaded from this origin, which the policy
 * allows without a nonce and without an exception.
 */

/**
 * A form that asks before it destroys something.
 *
 * `confirm()` is a blunt instrument and a dialog nobody reads is not consent —
 * but a delete that happens on one mistaken click is worse, and a real
 * confirmation screen is a feature with its own design rather than a line of
 * JavaScript.
 */
function confirmBeforeSubmitting() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
}

/**
 * An image whose bytes are gone.
 *
 * A record can outlive its file, and feature 002 decided that an article with a
 * missing image renders without it rather than not at all. A broken-image icon
 * in the middle of an article says "this site is broken" to a reader who cannot
 * tell the difference.
 */
function hideImagesThatCannotLoad() {
    document.querySelectorAll('img[data-hide-on-error]').forEach((image) => {
        image.addEventListener('error', () => {
            image.style.display = 'none';
        });
    });
}

confirmBeforeSubmitting();
hideImagesThatCannotLoad();
