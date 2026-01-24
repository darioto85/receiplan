import './stimulus_bootstrap.js';

/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';
import './styles/styles.css';

// ✅ Symfony UX Autocomplete (Tom Select + controller Stimulus côté UX)
import '@symfony/ux-autocomplete';

// ✅ CSS Tom Select (dans ton importmap.php, donc importable)
import 'tom-select/dist/css/tom-select.default.min.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
