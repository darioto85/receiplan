import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
  static values = {
    url: String,
    detailUrl: String,
    unitSelector: String,

    placeholder: { type: String, default: 'Rechercher…' },
    maxItems: { type: Number, default: 1 },
    minQuery: { type: Number, default: 1 },
    limit: { type: Number, default: 20 },
    createUrl: String,
    clearOnFocus: { type: Boolean, default: true },
  };

  connect() {
    if (this.element.tomselect) return;

    const url = this.urlValue || this.element.dataset.ingredientSelectUrlValue;
    if (!url) {
      console.warn('[ingredient-select] Missing data-ingredient-select-url-value');
      return;
    }

    this._onDropdownPointer = this._onDropdownPointer.bind(this);
    this._onModalSubmit = this._onModalSubmit.bind(this);
    this._onModalKeydown = this._onModalKeydown.bind(this);
    this._onFocus = this._onFocus.bind(this);

    const controller = this;

    const ts = new TomSelect(this.element, {
      maxItems: this.maxItemsValue,
      create: false,
      allowEmptyOption: true,
      placeholder: this.placeholderValue || this.element.getAttribute('placeholder') || 'Rechercher…',

      valueField: 'value',
      labelField: 'text',
      searchField: ['text'],

      preload: false,
      closeAfterSelect: true,

      load: (query, callback) => {
        const q = String(query || '').trim();
        if (q.length < this.minQueryValue) {
          callback();
          return;
        }

        const sep = url.includes('?') ? '&' : '?';
        const fetchUrl = `${url}${sep}q=${encodeURIComponent(q)}&limit=${encodeURIComponent(this.limitValue)}`;

        fetch(fetchUrl, { headers: { Accept: 'application/json' } })
          .then((r) => r.json())
          .then((json) => callback(Array.isArray(json) ? json : []))
          .catch(() => callback());
      },

      render: {
        no_results: (data, escape) => {
          const input = data?.input ? String(data.input) : '';
          const safe = escape(input);

          return `
            <div class="ts-no-results">
              <div class="text-muted small mb-2">Aucun ingrédient trouvé.</div>
              <button type="button"
                      class="btn btn-sm btn-primary w-100"
                      data-ts-add="1"
                      data-ts-add-value="${safe}">
                ➕ Ajouter “${safe}”
              </button>
            </div>
          `;
        },
      },

      onInitialize: function () {
        try {
          if (this.control_input) {
            this.control_input.setAttribute(
              'placeholder',
              controller.placeholderValue || controller.element.getAttribute('placeholder') || 'Rechercher…'
            );
            this.control_input.addEventListener('focus', controller._onFocus);
            this.control_input.addEventListener('click', controller._onFocus);
          }

          const currentValue = this.getValue();
          if (currentValue === '' || currentValue == null) {
            this.clear(true);
          }
        } catch (e) {}

        const dropdown = this.dropdown_content;
        if (dropdown) {
          dropdown.addEventListener('mousedown', controller._onDropdownPointer);
          dropdown.addEventListener('click', controller._onDropdownPointer);
        }

        // ✅ sélection existante -> auto unité
        this.on('item_add', async (value) => {
            const key = String(value ?? '');
            const opt = this.options?.[key] || this.options?.[value];

            const focusQty = () => {
                // TomSelect reprend souvent le focus juste après item_add
                setTimeout(() => controller._focusQuantityInputNear(controller.element), 0);
            };

            const unit = opt?.unit;
            if (unit) {
                controller._setUnitInForm(unit);
                focusQty();
                return;
            }

            // fallback serveur si unit pas dans l'option
            const detailUrl = controller.detailUrlValue || controller.element.dataset.ingredientSelectDetailUrlValue;
            if (!detailUrl) {
                focusQty();
                return;
            }

            try {
                const sep = detailUrl.includes('?') ? '&' : '?';
                const res = await fetch(`${detailUrl}${sep}id=${encodeURIComponent(key)}`, {
                headers: { Accept: 'application/json' },
                });
                const data = await res.json().catch(() => null);

                if (!res.ok || !data || data.status !== 'ok') {
                focusQty();
                return;
                }

                const fetchedUnit = data.unit;
                if (fetchedUnit) {
                try {
                    this.updateOption(key, {
                    ...(opt || {}),
                    value: key,
                    text: data.text || (opt?.text ?? ''),
                    unit: fetchedUnit,
                    });
                } catch (e) {}

                controller._setUnitInForm(fetchedUnit);
                }

                focusQty();
            } catch (e) {
                focusQty();
            }
            });
      },
    });

    this.ts = ts;
  }

  disconnect() {
    this._detachModalListeners();

    if (this.ts) {
      try {
        const dropdown = this.ts.dropdown_content;
        if (dropdown) {
          dropdown.removeEventListener('mousedown', this._onDropdownPointer);
          dropdown.removeEventListener('click', this._onDropdownPointer);
        }
        if (this.ts.control_input) {
          this.ts.control_input.removeEventListener('focus', this._onFocus);
          this.ts.control_input.removeEventListener('click', this._onFocus);
        }
      } catch (e) {}

      this.ts.destroy();
      this.ts = null;
    }
  }

  _onFocus() {
    if (!this.ts) return;

    const v = this.ts.getValue();

    if (this.clearOnFocusValue && v) {
      this.ts.clear(true);
      this.ts.open();
      return;
    }

    if (!v) this.ts.open();
  }

  _setUnitInForm(unitValue) {
    const form = this.element.closest('form');
    if (!form) return;

    // ✅ 1) le meilleur : un marker explicite dans Twig
    let unitSelect = form.querySelector('select[data-unit-select="1"]');

    // ✅ 2) fallback: selector explicite (si tu veux l’utiliser)
    if (!unitSelect) {
      const selector =
        this.unitSelectorValue ||
        this.element.dataset.ingredientSelectUnitSelectorValue ||
        null;
      if (selector) unitSelect = document.querySelector(selector);
    }

    // ✅ 3) fallback: ta classe UI
    if (!unitSelect) {
      unitSelect = form.querySelector('select.rp-quickadd__miniSelect');
    }

    if (!unitSelect) return;

    const v = String(unitValue);
    unitSelect.value = v;

    // sécurité si mismatch
    if (unitSelect.value !== v) {
      // au lieu de vider, on garde l'existant (ou on force g si tu préfères)
      // unitSelect.value = 'g';
      return;
    }

    unitSelect.dispatchEvent(new Event('change', { bubbles: true }));
  }

  _onDropdownPointer(e) {
    const btn = e.target.closest?.('[data-ts-add="1"]');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    const name = btn.getAttribute('data-ts-add-value') || '';
    const raw = this.ts?.lastQuery || name;
    const ingredientName = String(raw || '').trim();

    this._openCreateModal(ingredientName);

    try { this.ts.close(); } catch (err) {}
  }

  // ---- Modal
  _findModalEls() {
    const modalEl = document.getElementById('ingredientCreateModal');
    if (!modalEl) return null;

    const nameInput = document.getElementById('ingredientCreateName');
    const unitSelect = document.getElementById('ingredientCreateUnit');
    const errorBox = document.getElementById('ingredientCreateError');
    const submitBtn = document.getElementById('ingredientCreateSubmit');

    if (!nameInput || !unitSelect || !errorBox || !submitBtn) return null;
    return { modalEl, nameInput, unitSelect, errorBox, submitBtn };
  }

  _openCreateModal(prefillName) {
    const els = this._findModalEls();
    if (!els) {
      alert('Modale introuvable. Vérifie include(common/_modal-ingredient.html.twig) dans cette page.');
      return false;
    }

    this._activeModalEls = els;
    this._pendingCreateFor = this.element;

    els.errorBox.innerHTML = '';
    els.nameInput.value = prefillName || '';
    if (!els.unitSelect.value) els.unitSelect.value = 'g';

    this._attachModalListeners();

    if (window.bootstrap?.Modal) {
      this._bsModal = this._bsModal || new window.bootstrap.Modal(els.modalEl, {
        focus: true,
        backdrop: true,
        keyboard: true,
      });
      this._bsModal.show();
    } else {
      alert('Bootstrap Modal non disponible (bootstrap.bundle pas chargé).');
      return false;
    }

    setTimeout(() => {
      els.nameInput.focus();
      els.nameInput.select?.();
    }, 100);

    return true;
  }

  _attachModalListeners() {
    if (!this._activeModalEls) return;
    if (this._modalListenersAttached) return;
    this._modalListenersAttached = true;

    const { modalEl, submitBtn } = this._activeModalEls;

    submitBtn.addEventListener('click', this._onModalSubmit);
    modalEl.addEventListener('keydown', this._onModalKeydown);

    modalEl.addEventListener(
      'hidden.bs.modal',
      () => {
        this._detachModalListeners();
        this._pendingCreateFor = null;
        if (this._activeModalEls) this._activeModalEls.errorBox.innerHTML = '';
      },
      { once: true }
    );
  }

  _detachModalListeners() {
    if (!this._activeModalEls || !this._modalListenersAttached) return;

    this._modalListenersAttached = false;

    this._activeModalEls.submitBtn.removeEventListener('click', this._onModalSubmit);
    this._activeModalEls.modalEl.removeEventListener('keydown', this._onModalKeydown);
  }

  _onModalKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      this._onModalSubmit();
    }
  }

  async _onModalSubmit() {
    const els = this._activeModalEls;
    if (!els) return;

    els.errorBox.innerHTML = '';

    const name = String(els.nameInput.value || '').trim();
    const unit = String(els.unitSelect.value || 'g').trim();

    if (!name) {
      els.errorBox.innerHTML = `<div class="alert alert-danger mb-0">Nom requis.</div>`;
      els.nameInput.focus();
      return;
    }

    const createUrl =
      this.createUrlValue ||
      els.modalEl.dataset.ingredientCreateUrl ||
      '';

    const csrf =
      els.modalEl.dataset.ingredientCreateCsrf ||
      '';

    if (!createUrl) {
      els.errorBox.innerHTML = `<div class="alert alert-danger mb-0">URL de création manquante.</div>`;
      return;
    }

    els.submitBtn.disabled = true;

    try {
      const form = new FormData();
      form.append('name', name);
      form.append('unit', unit);
      if (csrf) form.append('_token', csrf);

      const res = await fetch(createUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body: form,
      });

      const data = await res.json().catch(() => null);

      if (!res.ok || !data || data.status !== 'ok') {
        const msg = (data && data.message) ? data.message : 'Erreur lors de la création.';
        els.errorBox.innerHTML = `<div class="alert alert-danger mb-0">${msg}</div>`;
        return;
      }

      const id = data.id;
      const text = data.name;
      const createdUnit = data.unit || unit;

      if (!id || !text) {
        els.errorBox.innerHTML = `<div class="alert alert-danger mb-0">Réponse serveur invalide.</div>`;
        return;
      }

      const targetSelect = this._pendingCreateFor || this.element;
      const ts = targetSelect.tomselect;

      if (ts) {
        ts.addOption({ value: id, text, unit: createdUnit });
        ts.refreshOptions(false);
        ts.addItem(String(id), true);
        ts.close();
      } else {
        targetSelect.value = String(id);
      }

      this._setUnitInForm(createdUnit);

      try { this._bsModal?.hide(); } catch (e) {}
      this._focusQuantityInputNear(targetSelect);
    } catch (err) {
      els.errorBox.innerHTML = `<div class="alert alert-danger mb-0">Erreur réseau.</div>`;
    } finally {
      els.submitBtn.disabled = false;
    }
  }

  _focusQuantityInputNear(selectEl) {
    const form = selectEl.closest('form');
    if (!form) return;

    const qtyInput =
      form.querySelector('input[name$="[quantity]"]') ||
      form.querySelector('input[name*="[quantity]"]');

    if (qtyInput) {
      setTimeout(() => {
        qtyInput.focus();
        qtyInput.select?.();
      }, 50);
    }
  }
}