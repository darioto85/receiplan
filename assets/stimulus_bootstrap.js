import { startStimulusApp } from '@symfony/stimulus-bundle';
import CollectionController from './controllers/collection_controller.js';
import IngredientCreateController from './controllers/ingredient_create_controller.js';
import IngredientCreateHookController from './controllers/ingredient_create_hook_controller.js';

const app = startStimulusApp();
app.register('collection', CollectionController);
app.register('ingredient-create', IngredientCreateController);
app.register('ingredient-create-hook', IngredientCreateHookController);
