import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    // listing
    'mobileList',
    'desktopTable',
    'desktopTbody',
    'empty',
    'count',
    'formErrors',

    // delete modal
    'deleteModal',
    'deleteName',
    'deleteError',
    'confirmDeleteBtn',

    // qty modal (mobile)
    'qtyModal',
    'qtyName',
    'qtyModalInput',
    'qtyUnit',
    'qtyError',
    'qtyConfirmBtn',
  ];

  connect() {
    this.pendingDeleteForm = null;
    this.bsDeleteModal = null;

    this.pendingQty = null; // {id, url, token}
    this.bsQtyModal = null;

    this.activeQtyEl = null;
    this.activePrevValue = null;

    // ✅ reset du champ à la fermeture (annuler / valider / croix)
    if (this.hasQtyModalTarget && this.hasQtyModalInputTarget) {
      this.qtyModalTarget.addEventListener('hidden.bs.modal', () => {
        this.qtyModalInputTarget.dataset.clearedOnce = '0';
        this.qtyModalInputTarget.dataset.prefillValue = '';
      });
    }

    // état initial cohérent
    this._syncEmptyState();
  }

  // =========================
  // UPSERT (AJAX add / increment)
  // =========================
  async submitUpsert(event) {
    event.preventDefault();

    const form = event.target;
    this._clearFormErrors();

    const res = await fetch(form.action, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: new FormData(form),
    });

    const data = await res.json().catch(() => null);

    if (!res.ok || !data || data.status !== 'ok') {
      const msg = data && data.message ? data.message : 'Erreur lors de l’ajout.';
      const errorsHtml = data && data.errors ? data.errors : null;
      this._showFormErrors(msg, errorsHtml);
      return;
    }

    if (this.hasCountTarget && typeof data.count === 'number') {
      this.countTarget.textContent = String(data.count);
    }

    // ✅ Desktop: insert/replace row
    if (this.hasDesktopTbodyTarget && data.htmlDesktop) {
      const existing = this.desktopTbodyTarget.querySelector(
        `[data-stock-item-id="${data.id}"]`
      );
      if (existing) existing.outerHTML = data.htmlDesktop;
      else this.desktopTbodyTarget.insertAdjacentHTML('afterbegin', data.htmlDesktop);
    }

    // ✅ Mobile: insert/replace item
    if (this.hasMobileListTarget && data.htmlMobile) {
      const existing = this.mobileListTarget.querySelector(
        `[data-stock-item-id="${data.id}"]`
      );
      if (existing) existing.outerHTML = data.htmlMobile;
      else this.mobileListTarget.insertAdjacentHTML('afterbegin', data.htmlMobile);
    }

    // ✅ IMPORTANT: supprimer/cacher tous les "empty" (mobile + desktop)
    this._removeAllEmptyPlaceholders();

    // ✅ et s'assurer que les conteneurs sont affichés
    this._syncEmptyState(true);

    form.reset();

    // TomSelect: clear selection if available
    const tsWrapper = form.querySelector('.ts-wrapper');
    if (tsWrapper && tsWrapper.tomselect) {
      tsWrapper.tomselect.clear(true);
    }
  }

  _clearFormErrors() {
    if (this.hasFormErrorsTarget) this.formErrorsTarget.innerHTML = '';
  }

  _showFormErrors(message, errorsHtml) {
    if (!this.hasFormErrorsTarget) return;

    const html = errorsHtml
      ? errorsHtml
      : `<div class="alert alert-danger mb-0">${String(message || 'Erreur.')}</div>`;

    this.formErrorsTarget.innerHTML = html;
  }

  // =========================
  // DELETE (open modal)
  // =========================
  deleteItem(event) {
    event.preventDefault();

    const form = event.target;
    this.pendingDeleteForm = form;

    const wrapper = form.closest('[data-stock-item-id]');
    const name = wrapper?.dataset?.stockItemName || 'cet ingrédient';

    if (this.hasDeleteNameTarget) this.deleteNameTarget.textContent = name;
    if (this.hasDeleteErrorTarget) this.deleteErrorTarget.innerHTML = '';

    this.bsDeleteModal = this.bsDeleteModal || new bootstrap.Modal(this.deleteModalTarget);
    this.bsDeleteModal.show();
  }

  async confirmDelete() {
    if (!this.pendingDeleteForm) return;

    const form = this.pendingDeleteForm;

    if (this.hasConfirmDeleteBtnTarget) this.confirmDeleteBtnTarget.disabled = true;
    if (this.hasDeleteErrorTarget) this.deleteErrorTarget.innerHTML = '';

    const res = await fetch(form.action, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: new FormData(form),
    });

    const data = await res.json().catch(() => null);

    if (!res.ok || !data || data.status !== 'ok') {
      const msg = data && data.message ? data.message : 'Erreur lors de la suppression.';
      if (this.hasDeleteErrorTarget) {
        this.deleteErrorTarget.innerHTML = `<div class="alert alert-danger mb-0">${msg}</div>`;
      }
      if (this.hasConfirmDeleteBtnTarget) this.confirmDeleteBtnTarget.disabled = false;
      return;
    }

    if (this.hasCountTarget && typeof data.count === 'number') {
      this.countTarget.textContent = String(data.count);
    }

    const selector = `[data-stock-item-id="${data.id}"]`;
    this.element.querySelectorAll(selector).forEach((el) => el.remove());

    // ✅ si on devient vide, on doit afficher l'état vide (si présent)
    this._syncEmptyState();

    if (this.bsDeleteModal) this.bsDeleteModal.hide();
    this.pendingDeleteForm = null;

    if (this.hasConfirmDeleteBtnTarget) this.confirmDeleteBtnTarget.disabled = false;
  }

  // =========================
  // DESKTOP inline quantity edit
  // =========================
  startEditQuantity(event) {
    const qtyEl = event.currentTarget;
    const input = qtyEl.querySelector('.rp-qty-input');
    const display = qtyEl.querySelector('[data-stock-qty-display]');
    if (!input || !display) return;

    this._cancelActiveInlineEdit();

    this.activeQtyEl = qtyEl;
    this.activePrevValue = qtyEl.dataset.stockQtyValue || input.value;

    qtyEl.classList.add('is-editing');
    input.value = this.activePrevValue;

    input.style.display = 'inline-block';
    display.style.display = 'none';

    input.focus();
    input.select();
  }

  quantityKeydown(event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      event.target.blur();
    }
    if (event.key === 'Escape') {
      event.preventDefault();
      this._cancelActiveInlineEdit();
    }
  }

  async commitEditQuantity(event) {
    const input = event.target;
    const qtyEl = input.closest('.rp-qty');
    if (!qtyEl) return;

    const newValue = this._parseQuantityInput(input.value);
    const prevValue = this.activePrevValue ?? (qtyEl.dataset.stockQtyValue || '');

    if (newValue === '' || newValue === prevValue) {
      this._cancelActiveInlineEdit(false);
      return;
    }

    await this._sendQuantityUpdate({
      id: qtyEl.dataset.stockQtyId,
      url: qtyEl.dataset.stockQtyUrl,
      token: qtyEl.dataset.stockQtyToken,
      quantity: newValue,
      onError: (msg) => {
        input.value = prevValue;
        this._cancelActiveInlineEdit(false);
        alert(msg);
      },
    });

    this._cancelActiveInlineEdit(false);
  }

  _cancelActiveInlineEdit(restorePrev = true) {
    if (!this.activeQtyEl) return;

    const qtyEl = this.activeQtyEl;
    const input = qtyEl.querySelector('.rp-qty-input');
    const display = qtyEl.querySelector('[data-stock-qty-display]');

    if (restorePrev && input && this.activePrevValue != null) {
      input.value = this.activePrevValue;
    }

    qtyEl.classList.remove('is-editing');
    if (display) display.style.display = 'inline';
    if (input) input.style.display = 'none';

    this.activeQtyEl = null;
    this.activePrevValue = null;
  }

  // =========================
  // MOBILE qty modal
  // =========================
  openQuantityModal(event) {
    const btn = event.currentTarget;

    this.pendingQty = {
      id: btn.dataset.stockQtyId,
      url: btn.dataset.stockQtyUrl,
      token: btn.dataset.stockQtyToken,
    };

    if (this.hasQtyNameTarget) this.qtyNameTarget.textContent = btn.dataset.stockQtyName || 'Ingrédient';

    if (this.hasQtyModalInputTarget) {
      const prefill = btn.dataset.stockQtyValue || '0.00';
      this.qtyModalInputTarget.value = prefill;
      this.qtyModalInputTarget.dataset.clearedOnce = '0';
      this.qtyModalInputTarget.dataset.prefillValue = prefill;
    }

    if (this.hasQtyUnitTarget) this.qtyUnitTarget.textContent = btn.dataset.stockQtyUnit || 'unité';
    if (this.hasQtyErrorTarget) this.qtyErrorTarget.innerHTML = '';

    this.bsQtyModal = this.bsQtyModal || new bootstrap.Modal(this.qtyModalTarget);
    this.bsQtyModal.show();

    setTimeout(() => this.qtyModalInputTarget?.focus(), 150);
  }

  clearQtyOnFocus(event) {
    const input = event.currentTarget;
    if (input.dataset.clearedOnce === '1') return;

    const current = String(input.value ?? '').trim();
    const prefill = String(input.dataset.prefillValue ?? '').trim();

    if (current === prefill) {
      input.value = '';
    } else if (current !== '') {
      input.select?.();
    }

    input.dataset.clearedOnce = '1';
  }

  async confirmQtyModal() {
    if (!this.pendingQty) return;

    if (this.hasQtyConfirmBtnTarget) this.qtyConfirmBtnTarget.disabled = true;
    if (this.hasQtyErrorTarget) this.qtyErrorTarget.innerHTML = '';

    const quantity = this._parseQuantityInput(this.qtyModalInputTarget.value);

    await this._sendQuantityUpdate({
      id: this.pendingQty.id,
      url: this.pendingQty.url,
      token: this.pendingQty.token,
      quantity,
      onError: (msg) => {
        if (this.hasQtyErrorTarget) {
          this.qtyErrorTarget.innerHTML = `<div class="alert alert-danger mb-0">${msg}</div>`;
        }
      },
    });

    if (this.hasQtyConfirmBtnTarget) this.qtyConfirmBtnTarget.disabled = false;
  }

  _parseQuantityInput(value) {
    const raw = String(value ?? '').trim().replace(',', '.');
    const match = raw.match(/-?\d+(\.\d+)?/);
    if (!match) return '';
    return match[0];
  }

  async _sendQuantityUpdate({ id, url, token, quantity, onError }) {
    if (quantity === '') {
      if (onError) onError('Quantité invalide.');
      return;
    }

    const form = new FormData();
    form.append('quantity', quantity);
    form.append('_token', token);

    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: form,
    });

    const data = await res.json().catch(() => null);

    if (!res.ok || !data || data.status !== 'ok') {
      const msg = data && data.message ? data.message : 'Erreur lors de la mise à jour.';
      if (onError) onError(msg);
      return;
    }

    this._applyQuantityToDom(data.id, data.quantity);

    if (this.bsQtyModal) this.bsQtyModal.hide();
    this.pendingQty = null;
  }

  _applyQuantityToDom(id, quantity) {
    this.element
      .querySelectorAll(`[data-stock-item-id="${id}"] [data-stock-qty-display]`)
      .forEach((n) => (n.textContent = quantity));

    this.element
      .querySelectorAll(`[data-stock-qty-id="${id}"]`)
      .forEach((el) => {
        el.dataset.stockQtyValue = quantity;
        const input = el.querySelector?.('.rp-qty-input');
        if (input) input.value = quantity;
      });
  }

  // =========================
  // Empty state handling (mobile + desktop)
  // =========================
  _removeAllEmptyPlaceholders() {
    if (!this.hasEmptyTarget) return;

    // Stimulus: emptyTargets = tous les éléments ayant data-stock-target="empty"
    const empties = this.emptyTargets || [];
    empties.forEach((el) => {
      const tag = (el.tagName || '').toLowerCase();
      if (tag === 'tr') {
        el.remove(); // desktop empty row
      } else {
        el.classList.add('d-none'); // mobile empty div
      }
    });
  }

  _syncEmptyState(forceNonEmpty = false) {
    let hasAny = forceNonEmpty;

    if (!hasAny) {
      if (this.hasMobileListTarget && this.mobileListTarget.querySelector('[data-stock-item-id]')) {
        hasAny = true;
      }
      if (!hasAny && this.hasDesktopTbodyTarget && this.desktopTbodyTarget.querySelector('[data-stock-item-id]')) {
        hasAny = true;
      }
    }

    if (hasAny) {
      this._removeAllEmptyPlaceholders();
    } else {
      // si on est vide, on ré-affiche les placeholders existants (si présents)
      const empties = this.emptyTargets || [];
      empties.forEach((el) => el.classList.remove('d-none'));
    }
  }
}