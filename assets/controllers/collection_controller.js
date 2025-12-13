import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['list'];
  static values = {
    prototype: String,
    index: Number,
  };

  connect() {
    console.log('collection connected');
  }

  add() {
    const html = this.prototypeValue.replace(/__name__/g, this.indexValue);
    this.indexValue++;

    // Le prototype Twig contient déjà le wrapper .row + le bouton supprimer,
    // donc on injecte tel quel.
    this.listTarget.insertAdjacentHTML('beforeend', html);
  }

  remove(event) {
    event.preventDefault();
    const item = event.target.closest('[data-collection-target="item"]');
    if (item) item.remove();
  }
}
