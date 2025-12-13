import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  connect() {
    this.waitForTomSelect();
  }

  waitForTomSelect(tries = 0) {
    if (this.element.tomselect) {
      this.patchCreate(this.element.tomselect);
      return;
    }
    if (tries < 20) {
      setTimeout(() => this.waitForTomSelect(tries + 1), 50);
    }
  }

  patchCreate(tomselect) {
    const selectId = this.element.id;

    tomselect.settings.create = (input, callback) => {
      // on annule le callback TomSelect => on ne veut pas d’item "texte"
      if (typeof callback === 'function') callback(null);

      window.dispatchEvent(new CustomEvent('ingredient-create:open', {
        detail: { input, selectId }
      }));
    };
  }
}
