import './stimulus_bootstrap.js';
import './behaviours.js';
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

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
