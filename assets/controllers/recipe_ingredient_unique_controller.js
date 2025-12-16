import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['select'];

  connect() {
    // Mise à jour au chargement
    this.refresh();

    // À chaque changement sur un select
    this.element.addEventListener('change', (e) => {
      if (e.target && e.target.matches('[data-recipe-ingredient-unique-target="select"]')) {
        this.refresh();
      }
    });

    // Quand ta collection ajoute/supprime une ligne, on refresh aussi.
    // (au cas où ton controller collection ne déclenche pas d’event spécifique)
    const observer = new MutationObserver(() => this.refresh());
    observer.observe(this.element, { childList: true, subtree: true });
    this._observer = observer;
  }

  disconnect() {
    if (this._observer) this._observer.disconnect();
  }

  refresh() {
    const selects = this.selectTargets;

    // Collecte des valeurs sélectionnées (hors vide)
    const selected = new Set(
      selects.map(s => s.value).filter(v => v && v !== '')
    );

    // Pour chaque select, désactive les options déjà prises ailleurs
    selects.forEach((select) => {
      const currentValue = select.value;

      // TomSelect ?
      if (select.tomselect) {
        const ts = select.tomselect;

        // Réactive tout d'abord
        Object.keys(ts.options).forEach((value) => {
          ts.updateOption(value, { ...ts.options[value], disabled: false });
        });

        // Désactive les valeurs déjà sélectionnées ailleurs
        selected.forEach((value) => {
          if (value !== currentValue && ts.options[value]) {
            ts.updateOption(value, { ...ts.options[value], disabled: true });
          }
        });

        ts.refreshOptions(false);
        return;
      }

      // Fallback select natif
      Array.from(select.options).forEach((opt) => {
        if (!opt.value) return;
        opt.disabled = (selected.has(opt.value) && opt.value !== currentValue);
      });
    });
  }
}
