// assets/controllers/recipe_ingredients_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    upsertUrl: String,
    previewUrl: String,
  };

  static targets = [
    "formErrors",
    "listing",

    "mobileList",
    "desktopTbody",

    "deleteModal",
    "deleteName",
    "deleteError",
    "confirmDeleteBtn",

    "qtyModal",
    "qtyName",
    "qtyUnit",
    "qtyModalInput",
    "qtyError",
    "qtyConfirmBtn",
  ];

  connect() {
    this._deleteContext = null; // { url, token, id, rowEl }
    this._qtyContext = null;    // { url, token, id, displayEl, inputEl? }
  }

  // =========================
  // UPSERT (AJAX)
  // =========================
  async submitUpsert(event) {
    event.preventDefault();

    this._clearFormErrors();

    const form = event.currentTarget;
    const action = form.getAttribute("action") || this.upsertUrlValue;
    const formData = new FormData(form);

    try {
      const res = await fetch(action, {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        body: formData,
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        if (data?.errors) {
          this._setFormErrorsHtml(data.errors);
        } else {
          this._setFormErrorsHtml(
            `<div class="alert alert-danger mb-0">${this._escapeHtml(data?.message || "Formulaire invalide.")}</div>`
          );
        }
        return;
      }

      // Insertion / remplacement item desktop
      if (data?.htmlDesktop) {
        this._upsertDesktopRow(data.id, data.htmlDesktop, data.isNew);
      }
      // Insertion / remplacement item mobile
      if (data?.htmlMobile) {
        this._upsertMobileItem(data.id, data.htmlMobile, data.isNew);
      }

      // Reset champ quantity (et garde l’ingrédient sélectionné si tu veux)
      const qtyInput = form.querySelector('input[name$="[quantity]"], input[name="quantity"]');
      if (qtyInput) qtyInput.value = "";

      // Scroll listing léger (optionnel)
      this.listingTarget?.scrollIntoView?.({ behavior: "smooth", block: "start" });

    } catch (e) {
      this._setFormErrorsHtml(`<div class="alert alert-danger mb-0">Erreur réseau. Réessaie.</div>`);
    }
  }

  _upsertDesktopRow(id, html, isNew) {
    if (!this.hasDesktopTbodyTarget) return;

    const existing = this.desktopTbodyTarget.querySelector(
      `[data-recipe-ingredients-item-id="${CSS.escape(String(id))}"]`
    );

    const tmp = document.createElement("tbody");
    tmp.innerHTML = html.trim();
    const newRow = tmp.firstElementChild;

    if (!newRow) return;

    if (existing) {
      existing.replaceWith(newRow);
    } else {
      // Stock: append en bas (ici pareil)
      this.desktopTbodyTarget.appendChild(newRow);
    }
  }

  _upsertMobileItem(id, html, isNew) {
    if (!this.hasMobileListTarget) return;

    const existing = this.mobileListTarget.querySelector(
      `[data-recipe-ingredients-item-id="${CSS.escape(String(id))}"]`
    );

    const tmp = document.createElement("div");
    tmp.innerHTML = html.trim();
    const newItem = tmp.firstElementChild;

    if (!newItem) return;

    if (existing) {
      existing.replaceWith(newItem);
    } else {
      // Stock: souvent prepend pour visibilité; ici on prepend
      this.mobileListTarget.prepend(newItem);
    }
  }

  _setFormErrorsHtml(html) {
    if (!this.hasFormErrorsTarget) return;
    this.formErrorsTarget.innerHTML = html || "";
  }

  _clearFormErrors() {
    if (!this.hasFormErrorsTarget) return;
    this.formErrorsTarget.innerHTML = "";
  }

  // =========================
  // DELETE (AJAX + modal)
  // =========================
  deleteItem(event) {
    // intercept submit delete (desktop & mobile)
    event.preventDefault();

    const form = event.currentTarget;
    const rowEl = this._findItemRoot(form);
    const id = rowEl?.dataset?.recipeIngredientsItemId;
    const name = rowEl?.dataset?.recipeIngredientsItemName || "cet ingrédient";

    const url = form.getAttribute("action");
    const tokenInput = form.querySelector('input[name="_token"]');
    const token = tokenInput ? tokenInput.value : "";

    this._deleteContext = { url, token, id, rowEl };

    this._openDeleteModal(name);
  }

  _openDeleteModal(name) {
    if (!this.hasDeleteModalTarget) return;

    this.deleteNameTarget.textContent = name;
    this.deleteErrorTarget.innerHTML = "";
    this.confirmDeleteBtnTarget.disabled = false;

    this._deleteModalInstance = this._deleteModalInstance || new window.bootstrap.Modal(this.deleteModalTarget);
    this._deleteModalInstance.show();
  }

  async confirmDelete() {
    if (!this._deleteContext) return;

    const { url, token, id, rowEl } = this._deleteContext;

    this.confirmDeleteBtnTarget.disabled = true;
    this.deleteErrorTarget.innerHTML = "";

    const body = new URLSearchParams();
    body.append("_token", token);

    try {
      const res = await fetch(url, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || data?.status !== "ok") {
        const msg = data?.message || "Impossible de supprimer.";
        this.deleteErrorTarget.innerHTML = `<div class="alert alert-danger mb-0">${this._escapeHtml(msg)}</div>`;
        this.confirmDeleteBtnTarget.disabled = false;
        return;
      }

      // Retire ligne du DOM (desktop ou mobile)
      this._removeItemById(id);

      // Ferme modale
      this._deleteModalInstance?.hide?.();
      this._deleteContext = null;

    } catch (e) {
      this.deleteErrorTarget.innerHTML = `<div class="alert alert-danger mb-0">Erreur réseau. Réessaie.</div>`;
      this.confirmDeleteBtnTarget.disabled = false;
    }
  }

  _removeItemById(id) {
    if (!id) return;

    // Mobile
    if (this.hasMobileListTarget) {
      const mobileEl = this.mobileListTarget.querySelector(
        `[data-recipe-ingredients-item-id="${CSS.escape(String(id))}"]`
      );
      mobileEl?.remove();
    }

    // Desktop
    if (this.hasDesktopTbodyTarget) {
      const desktopEl = this.desktopTbodyTarget.querySelector(
        `[data-recipe-ingredients-item-id="${CSS.escape(String(id))}"]`
      );
      desktopEl?.remove();
    }
  }

  // =========================
  // QUANTITY (mobile modal)
  // =========================
  openQuantityModal(event) {
    const btn = event.currentTarget;

    const id = btn.dataset.recipeIngredientsQtyId;
    const name = btn.dataset.recipeIngredientsQtyName || "Ingrédient";
    const value = btn.dataset.recipeIngredientsQtyValue || "";
    const unit = btn.dataset.recipeIngredientsQtyUnit || "unité";
    const url = btn.dataset.recipeIngredientsQtyUrl;
    const token = btn.dataset.recipeIngredientsQtyToken;

    this._qtyContext = {
      id,
      url,
      token,
      displayEl: btn.querySelector("[data-recipe-ingredients-qty-display]") || null,
    };

    this.qtyNameTarget.textContent = name;
    this.qtyUnitTarget.textContent = unit;
    this.qtyModalInputTarget.value = value;
    this.qtyErrorTarget.innerHTML = "";
    this.qtyConfirmBtnTarget.disabled = false;

    this._qtyModalInstance = this._qtyModalInstance || new window.bootstrap.Modal(this.qtyModalTarget);
    this._qtyModalInstance.show();

    setTimeout(() => this.qtyModalInputTarget.focus(), 150);
  }

  clearQtyOnFocus() {
    // UX comme stock : vide au focus si valeur "0.00" etc (optionnel)
    // Ici on ne force rien, mais tu peux décommenter si tu veux.
    // this.qtyModalInputTarget.select();
  }

  async confirmQtyModal() {
    if (!this._qtyContext) return;

    const { url, token, id, displayEl } = this._qtyContext;

    this.qtyErrorTarget.innerHTML = "";
    this.qtyConfirmBtnTarget.disabled = true;

    const raw = (this.qtyModalInputTarget.value || "").trim();

    const body = new URLSearchParams();
    body.append("_token", token);
    body.append("quantity", raw);

    try {
      const res = await fetch(url, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || data?.status !== "ok") {
        const msg = data?.message || "Impossible de modifier la quantité.";
        this.qtyErrorTarget.innerHTML = `<div class="alert alert-danger mb-0">${this._escapeHtml(msg)}</div>`;
        this.qtyConfirmBtnTarget.disabled = false;
        return;
      }

      // Update affichage mobile + dataset
      if (displayEl) displayEl.textContent = data.quantity;

      // Update dataset value sur le bouton (pour réouverture)
      // Le bouton est l’élément cliqué initialement, mais on ne le stocke pas.
      // On resynchronise aussi desktop si présent :
      this._syncQuantityEverywhere(id, data.quantity);

      this._qtyModalInstance?.hide?.();
      this._qtyContext = null;

    } catch (e) {
      this.qtyErrorTarget.innerHTML = `<div class="alert alert-danger mb-0">Erreur réseau. Réessaie.</div>`;
      this.qtyConfirmBtnTarget.disabled = false;
    }
  }

  _syncQuantityEverywhere(id, quantity) {
    // Mobile: update dataset + display
    if (this.hasMobileListTarget) {
      const mobileRoot = this.mobileListTarget.querySelector(
        `[data-recipe-ingredients-item-id="${CSS.escape(String(id))}"]`
      );
      if (mobileRoot) {
        const qtyBtn = mobileRoot.querySelector(`[data-recipe-ingredients-qty-id="${CSS.escape(String(id))}"]`);
        if (qtyBtn) {
          qtyBtn.dataset.recipeIngredientsQtyValue = quantity;
          const display = qtyBtn.querySelector("[data-recipe-ingredients-qty-display]");
          if (display) display.textContent = quantity;
        }
      }
    }

    // Desktop: update span + input
    if (this.hasDesktopTbodyTarget) {
      const desktopRoot = this.desktopTbodyTarget.querySelector(
        `[data-recipe-ingredients-item-id="${CSS.escape(String(id))}"]`
      );
      if (desktopRoot) {
        const qtySpan = desktopRoot.querySelector(`[data-recipe-ingredients-qty-id="${CSS.escape(String(id))}"]`);
        if (qtySpan) {
          qtySpan.dataset.recipeIngredientsQtyValue = quantity;
          const display = qtySpan.querySelector("[data-recipe-ingredients-qty-display]");
          if (display) display.textContent = quantity;
          const input = qtySpan.querySelector("input.rp-qty-input");
          if (input) input.value = quantity;
        }
      }
    }
  }

  // =========================
  // QUANTITY (desktop inline)
  // =========================
  startEditQuantity(event) {
    const container = event.currentTarget;
    const input = container.querySelector("input.rp-qty-input");
    if (!input) return;

    container.classList.add("is-editing");
    input.style.display = "inline-block";
    input.focus();
    input.select();
  }

  async commitEditQuantity(event) {
    const input = event.currentTarget;
    const container = input.closest("[data-recipe-ingredients-qty-id]");
    if (!container) return;

    const id = container.dataset.recipeIngredientsQtyId;
    const url = container.dataset.recipeIngredientsQtyUrl;
    const token = container.dataset.recipeIngredientsQtyToken;

    const raw = (input.value || "").trim();

    const body = new URLSearchParams();
    body.append("_token", token);
    body.append("quantity", raw);

    try {
      const res = await fetch(url, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || data?.status !== "ok") {
        // revert to previous value stored in dataset
        input.value = container.dataset.recipeIngredientsQtyValue || input.value;
        this._toastError(data?.message || "Quantité invalide.");
        this._stopInlineEdit(container);
        return;
      }

      container.dataset.recipeIngredientsQtyValue = data.quantity;
      const display = container.querySelector("[data-recipe-ingredients-qty-display]");
      if (display) display.textContent = data.quantity;

      this._syncQuantityEverywhere(id, data.quantity);
      this._stopInlineEdit(container);

    } catch (e) {
      input.value = container.dataset.recipeIngredientsQtyValue || input.value;
      this._toastError("Erreur réseau.");
      this._stopInlineEdit(container);
    }
  }

  quantityKeydown(event) {
    if (event.key === "Enter") {
      event.preventDefault();
      event.currentTarget.blur();
    }
    if (event.key === "Escape") {
      const input = event.currentTarget;
      const container = input.closest("[data-recipe-ingredients-qty-id]");
      if (!container) return;

      input.value = container.dataset.recipeIngredientsQtyValue || input.value;
      this._stopInlineEdit(container);
    }
  }

  _stopInlineEdit(container) {
    const input = container.querySelector("input.rp-qty-input");
    if (input) {
      input.style.display = "";
    }
    container.classList.remove("is-editing");
  }

  // =========================
  // Helpers
  // =========================
  _findItemRoot(el) {
    // desktop tr or mobile .swipe-item
    return (
      el.closest("[data-recipe-ingredients-item-id]") ||
      el.closest(".swipe-item")
    );
  }

  _toastError(message) {
    // simple fallback : tu peux brancher ton système de toast si tu en as un
    // Ici: console + petit alert silent option
    console.warn(message);
  }

  _escapeHtml(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }
}
