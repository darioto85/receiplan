import { startStimulusApp } from '@symfony/stimulus-bundle';
import CollectionController from './controllers/collection_controller.js';

const app = startStimulusApp();
app.register('collection', CollectionController);
