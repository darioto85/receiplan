import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['nameInput', 'unitInput', 'nameError', 'unitError', 'csrfToken'];

  connect() {
    this.modalEl = this.element; // controller sur la div #ingredientCreateModal
    this.modal = null;

    // ✅ Important: si la modale est rendue dans un container AJAX, elle peut passer sous le backdrop.
    // On la déplace dans <body> dès la connexion (safe) :
    this.ensureInBody();

    if (window.bootstrap && window.bootstrap.Modal) {
      this.modal = new window.bootstrap.Modal(this.modalEl, {
        focus: true,
        backdrop: true,
        keyboard: true,
      });

      // ✅ Nettoyage robuste après fermeture
      this._onHidden = () => this.cleanupBackdrops();
      this.modalEl.addEventListener('hidden.bs.modal', this._onHidden);
    }

    // écoute les demandes d'ouverture venant des selects
    this._onOpen = (event) => this.openFromEvent(event);
    window.addEventListener('ingredient-create:open', this._onOpen);
  }

  disconnect() {
    window.removeEventListener('ingredient-create:open', this._onOpen);

    if (this._onHidden) {
      this.modalEl.removeEventListener('hidden.bs.modal', this._onHidden);
    }
  }

  ensureInBody() {
    // Déplace la modale sous <body> pour éviter les problèmes de stacking context
    if (this.modalEl.parentElement !== document.body) {
      document.body.appendChild(this.modalEl);
    }
  }

  cleanupBackdrops() {
    // Supprime les backdrops "fantômes" + restore body state
    document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');
  }

  openFromEvent(event) {
    this.clearErrors();

    const { input, selectId } = event.detail || {};
    this.currentSelectId = selectId;

    this.nameInputTarget.value = input || '';
    this.unitInputTarget.value = '';

    if (!this.modal) {
      alert("Bootstrap Modal non chargé. Vérifie bootstrap.bundle.min.js");
      return;
    }

    // ✅ Sécurité : si une autre modale est déjà ouverte, on la ferme (évite double backdrop)
    document.querySelectorAll('.modal.show').forEach((el) => {
      if (el !== this.modalEl) {
        window.bootstrap.Modal.getInstance(el)?.hide();
      }
    });

    // ✅ Au cas où il y a un backdrop orphelin
    this.cleanupBackdrops();

    // ✅ Re-garantie placement (utile si ton DOM est re-rendu)
    this.ensureInBody();

    this.modal.show();
  }

  async submit() {
    this.clearErrors();

    const name = this.nameInputTarget.value.trim();
    const unit = this.unitInputTarget.value.trim();

    let hasError = false;
    if (!name) {
      this.showError(this.nameInputTarget, this.nameErrorTarget, 'Le nom est obligatoire.');
      hasError = true;
    }
    if (!unit) {
      this.showError(this.unitInputTarget, this.unitErrorTarget, 'L’unité est obligatoire.');
      hasError = true;
    }
    if (hasError) return;

    const res = await fetch('/ingredient/quick-create', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name,
        unit,
        _token: this.csrfTokenTarget.value,
      }),
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
      const msg = data.error || 'Erreur lors de la création.';
      this.showError(this.nameInputTarget, this.nameErrorTarget, msg);
      return;
    }

    const select = document.getElementById(this.currentSelectId);
    if (!select || !select.tomselect) {
      this.showError(this.nameInputTarget, this.nameErrorTarget, "Impossible de retrouver le champ d'origine.");
      return;
    }

    const ts = select.tomselect;
    const id = String(data.id);
    const label = data.name;

    ts.addOption({ value: id, text: label });
    ts.refreshOptions(false);
    ts.addItem(id, true);

    if (this.modal) this.modal.hide();
  }

  clearErrors() {
    this.nameInputTarget.classList.remove('is-invalid');
    this.unitInputTarget.classList.remove('is-invalid');
    this.nameErrorTarget.textContent = '';
    this.unitErrorTarget.textContent = '';
  }

  showError(inputEl, errorEl, message) {
    inputEl.classList.add('is-invalid');
    errorEl.textContent = message;
  }
}
