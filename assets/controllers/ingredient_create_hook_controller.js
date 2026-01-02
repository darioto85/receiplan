import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  connect() {
    this.waitForTomSelect();
    this._opening = false;
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
      if (typeof callback === 'function') callback(null);

      if (this._opening) return;
      this._opening = true;

      window.dispatchEvent(new CustomEvent('ingredient-create:open', {
        detail: { input, selectId }
      }));

      // reset au prochain tick
      setTimeout(() => { this._opening = false; }, 0);
    };
  }
}
