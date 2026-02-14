import './stimulus_bootstrap.js';

/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

import './styles/library/bootstrap.css';
import './styles/app.css';
//import './styles/styles.css';
import './styles/partials/assistant.css';
import './styles/partials/common.css';
import './styles/partials/mealplan.css';
import './styles/partials/recipe.css';
import './styles/partials/shopping.css';
import './styles/partials/stock.css';

// ✅ Symfony UX Autocomplete (Tom Select + controller Stimulus côté UX)
import '@symfony/ux-autocomplete';

// ✅ CSS Tom Select (dans ton importmap.php, donc importable)
import 'tom-select/dist/css/tom-select.default.min.css';

