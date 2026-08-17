import './stimulus_bootstrap.js';
import './behaviours.js';
import { enhanceSearchBoxes, revertSearchBoxes } from './suggestions.js';
import { enhanceMarkupFields, revertMarkupFields } from './editor.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
/*
 * The stylesheet is linked directly in base.html.twig, so importing it here
 * would be the second way to load the same file. AssetMapper answers such an
 * import with an empty `data:` module, which the content security policy would
 * then have to allow as a script source — a real hole opened to load nothing.
 */

/*
 * Both are enhancements over markup that already works, so both look for what
 * they can improve and do nothing when they find none.
 *
 * **Turbo is why this is not simply two calls.** `@symfony/ux-turbo` is enabled
 * in assets/controllers.json, so Turbo Drive replaces the whole of `<body>` on
 * every visit and stores a snapshot of the page before leaving it. Two
 * consequences, and both used to be bugs here:
 *
 * - anything built by script is thrown away on the next visit and has to be
 *   built again, which is what `turbo:load` is for — it fires on the first load
 *   as well as after each visit;
 * - anything built by script is *in* the snapshot unless it is taken out first,
 *   so going back would restore a dropdown with no script behind it and a
 *   toolbar whose buttons do nothing.
 *
 * The readyState fallback covers a page where Turbo never starts. Both
 * enhancements are idempotent, so the overlap costs nothing.
 */
function enhance() {
    enhanceSearchBoxes();
    enhanceMarkupFields();
}

function revert() {
    revertSearchBoxes();
    revertMarkupFields();
}

document.addEventListener('turbo:load', enhance);
document.addEventListener('turbo:before-cache', revert);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance);
} else {
    enhance();
}
