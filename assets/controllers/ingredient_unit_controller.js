import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['select', 'unit'];

  connect() {
    this.cache = new Map(); // id -> unit
    this.update(); // au chargement (utile en edit)
  }

  async update() {
    const id = this.selectTarget.value;

    if (!id) {
      this.unitTarget.textContent = '';
      return;
    }

    if (this.cache.has(id)) {
      this.unitTarget.textContent = this.cache.get(id);
      return;
    }

    try {
      const res = await fetch(`/ingredient/${id}/unit`, {
        headers: { 'Accept': 'application/json' },
      });

      if (!res.ok) {
        this.unitTarget.textContent = '';
        return;
      }

      const data = await res.json();
      const unit = data.unit || '';

      this.cache.set(id, unit);
      this.unitTarget.textContent = unit;
    } catch (e) {
      this.unitTarget.textContent = '';
    }
  }
}
