// assets/controllers/shopping_controller.js
import { Controller } from "@hotwired/stimulus";

/**
 * Shopping list controller
 *
 * Requis côté Twig (exemple):
 * <div
 *   data-controller="shopping"
 *   data-shopping-toggle-url-value="{{ path('shopping_toggle', { id: 0 }) }}"
 *   data-shopping-validate-url-value="{{ path('shopping_validate_cart') }}"
 *   data-shopping-update-quantity-url-value="{{ path('shopping_update_quantity', { id: 0 }) }}"
 *   data-shopping-csrf-token-value="{{ csrf_token('shopping_cart') }}"
 * >
 *
 *  <input type="checkbox"
 *    data-shopping-target="checkbox"
 *    data-action="change->shopping#toggle"
 *    data-shopping-id-param="{{ item.id }}"
 *  >
 *
 *  <input type="number"
 *    data-action="change->shopping#updateQuantity"
 *    data-shopping-id-param="{{ item.id }}"
 *    value="{{ item.quantity }}"
 *  >
 *
 *  <button data-shopping-target="validateBtn" data-action="shopping#validateCart" disabled>Valider</button>
 * </div>
 */
export default class extends Controller {
  static values = {
    toggleUrl: String, // ex: "/shopping/0/toggle"
    validateUrl: String, // ex: "/shopping/validate-cart"
    updateQuantityUrl: String, // ex: "/shopping/0/quantity"
    csrfToken: String,
  };

  static targets = ["checkbox", "validateBtn", "countBadge"];

  connect() {
    this.refreshValidateButtonState();
    this.refreshCountBadge();
  }

  /**
   * Checkbox change => cocher / décocher (annuler)
   */
  async toggle(event) {
    const checkbox = event.currentTarget;
    const id = event.params.id;
    if (!id) return;

    const checked = checkbox.checked;
    checkbox.disabled = true;

    try {
      const url = this.buildUrlWithId(this.toggleUrlValue, id);

      const form = new URLSearchParams();
      form.set("checked", checked ? "1" : "0");

      const res = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          Accept: "application/json",
          ...this.csrfHeader(),
        },
        body: form.toString(),
      });

      const data = await this.safeJson(res);
      if (!res.ok || !data?.ok) {
        checkbox.checked = !checked; // rollback
        console.error("toggle failed", data);
        return;
      }

      const row = this.findRowForId(id);
      if (row) row.classList.toggle("opacity-50", checked);
    } catch (e) {
      checkbox.checked = !checked;
      console.error(e);
    } finally {
      checkbox.disabled = false;
      this.refreshValidateButtonState();
      this.refreshCountBadge();
    }
  }

  /**
   * Edition quantité (change) => POST updateQuantityUrl
   * Règle UX: 0 => suppression (endpoint retourne removed:true)
   */
  async updateQuantity(event) {
    const input = event.currentTarget;
    const id = event.params.id;
    if (!id) return;

    const raw = String(input.value ?? "").trim().replace(",", ".");
    input.disabled = true;

    try {
      const data = await this.postQuantity(id, raw);
      if (!data?.ok) {
        console.error("updateQuantity failed", data);
        return;
      }

      if (data.removed) {
        const row = this.findRowForId(id);
        if (row) row.remove();
      } else if (typeof data.quantity !== "undefined") {
        input.value = data.quantity;
      }
    } catch (e) {
      console.error(e);
    } finally {
      input.disabled = false;
      this.refreshValidateButtonState();
      this.refreshCountBadge();
    }
  }

  /**
   * Action "Supprimer" (menu ⋯) :
   * - envoie quantity=0 au même endpoint
   * - retire la ligne/card du DOM
   */
  async remove(event) {
    event?.preventDefault?.();

    const id = event.params.id;
    if (!id) return;

    if (!confirm("Supprimer cet article de la liste ?")) return;

    const row = this.findRowForId(id);

    // Désactive les inputs/checkbox de la ligne pendant l'action
    const checkbox = row?.querySelector('[data-shopping-target="checkbox"]');
    const qtyInput = row?.querySelector('input[type="number"]');
    if (checkbox) checkbox.disabled = true;
    if (qtyInput) qtyInput.disabled = true;

    try {
      const data = await this.postQuantity(id, "0");
      if (!data?.ok) {
        console.error("remove failed", data);
        return;
      }

      if (row) row.remove();
    } catch (e) {
      console.error(e);
    } finally {
      this.refreshValidateButtonState();
      this.refreshCountBadge();
    }
  }

  /**
   * Valider le caddie :
   * - POST validateUrl
   * - si ok : supprime de l'UI les lignes cochées
   */
  async validateCart(event) {
    event?.preventDefault?.();

    const btn = this.hasValidateBtnTarget ? this.validateBtnTarget : null;
    if (btn) btn.disabled = true;

    try {
      const res = await fetch(this.validateUrlValue, {
        method: "POST",
        headers: {
          Accept: "application/json",
          ...this.csrfHeader(),
        },
      });

      const data = await this.safeJson(res);
      if (!res.ok || !data?.ok) {
        console.error("validateCart failed", data);
        return;
      }

      // Supprimer toutes les lignes cochées côté UI
      this.checkboxTargets.forEach((cb) => {
        if (!cb.checked) return;

        // On préfère l'API Stimulus (event.params) mais ici on lit le dataset
        const id =
          cb.dataset.shoppingIdParam ||
          cb.getAttribute("data-shopping-id-param");

        const row = id ? this.findRowForId(id) : cb.closest("tr");
        if (row) row.remove();
      });
    } catch (e) {
      console.error(e);
    } finally {
      this.refreshValidateButtonState();
      this.refreshCountBadge();
      if (btn) btn.disabled = false;
    }
  }

  // -------- Helpers --------

  buildUrlWithId(templateUrl, id) {
    // Supporte "/0/" ou "0" dans l'URL
    return String(templateUrl).replace("/0/", `/${id}/`).replace("0", String(id));
  }

  async postQuantity(id, rawQty) {
    const url = this.buildUrlWithId(this.updateQuantityUrlValue, id);

    const form = new URLSearchParams();
    form.set("quantity", String(rawQty).trim().replace(",", "."));

    const res = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        Accept: "application/json",
        ...this.csrfHeader(),
      },
      body: form.toString(),
    });

    const data = await this.safeJson(res);
    if (!res.ok) {
      // on renvoie quand même le payload pour debug
      return data ?? { ok: false };
    }

    return data;
  }

  csrfHeader() {
    if (this.hasCsrfTokenValue && this.csrfTokenValue) {
      return { "X-CSRF-TOKEN": this.csrfTokenValue };
    }

    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta?.content) {
      return { "X-CSRF-TOKEN": meta.content };
    }

    return {};
  }

  findRowForId(id) {
    // Support table row ET card (div) : on cherche n'importe quel élément porteur
    return this.element.querySelector(
      `[data-shopping-row-id="${CSS.escape(String(id))}"]`
    );
  }

  refreshValidateButtonState() {
    if (!this.hasValidateBtnTarget) return;

    // Si aucune checkbox (liste vide), bouton disabled
    const anyChecked = this.hasCheckboxTarget
      ? this.checkboxTargets.some((cb) => cb.checked)
      : false;

    this.validateBtnTarget.disabled = !anyChecked;
  }

  refreshCountBadge() {
    if (!this.hasCountBadgeTarget) return;

    const checkedCount = this.hasCheckboxTarget
      ? this.checkboxTargets.filter((cb) => cb.checked).length
      : 0;

    this.countBadgeTarget.textContent = String(checkedCount);
    this.countBadgeTarget.classList.toggle("d-none", checkedCount === 0);
  }

  async safeJson(res) {
    try {
      return await res.json();
    } catch {
      return null;
    }
  }
}
