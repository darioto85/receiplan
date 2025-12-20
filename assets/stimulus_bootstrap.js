import { startStimulusApp } from '@symfony/stimulus-bundle';
import CollectionController from './controllers/collection_controller.js';
import IngredientCreateController from './controllers/ingredient_create_controller.js';
import IngredientCreateHookController from './controllers/ingredient_create_hook_controller.js';
import IngredientUnitController from './controllers/ingredient_unit_controller.js';
import VoiceInputController from './controllers/voice_input_controller.js';
import MealPlanController from './controllers/meal_plan_controller.js';

const app = startStimulusApp();
app.register('collection', CollectionController);
app.register('ingredient-create', IngredientCreateController);
app.register('ingredient-create-hook', IngredientCreateHookController);
app.register('ingredient-unit', IngredientUnitController);
app.register('voice-input', VoiceInputController);
app.register('meal-plan', MealPlanController);
