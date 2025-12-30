// assets/controllers/shopping_controller.js
import { Controller } from "@hotwired/stimulus";

/**
 * Usage (Twig - exemple)
 * <div data-controller="shopping"
 *      data-shopping-toggle-url-value="{{ path('shopping_toggle', {id: 0}) }}"
 *      data-shopping-validate-url-value="{{ path('shopping_validate_cart') }}"
 *      data-shopping-csrf-token-value="{{ csrf_token('shopping_cart') }}">
 *
 *   <button data-action="shopping#validateCart" data-shopping-target="validateBtn">Valider mon caddie</button>
 *
 *   <input type="checkbox"
 *          data-action="change->shopping#toggle"
 *          data-shopping-id-param="{{ item.id }}"
 *          data-shopping-target="checkbox"
 *          {% if item.checked %}checked{% endif %}>
 *
 *   <tr data-shopping-row-id="{{ item.id }}" data-shopping-target="row">...</tr>
 * </div>
 *
 * Notes:
 * - toggle: l'utilisateur peut cocher (valider) ou décocher (annuler) au fil des rayons.
 * - validateCart: ajoute au stock tous les items cochés et les supprime de la liste (réponse JSON).
 */
export default class extends Controller {
  static values = {
    toggleUrl: String,   // ex: "/shopping/0/toggle" (0 sera remplacé par l'id)
    validateUrl: String, // ex: "/shopping/validate-cart"
    csrfToken: String,   // token CSRF (optionnel mais recommandé)
  };

  static targets = ["checkbox", "row", "validateBtn", "countBadge"];

  connect() {
    this.refreshValidateButtonState();
  }

  /**
   * Checkbox change => cocher (valider) / décocher (annuler)
   * data-shopping-id-param doit être fourni sur l'input
   */
  async toggle(event) {
    const checkbox = event.currentTarget;
    const id = event.params.id;
    if (!id) return;

    const checked = checkbox.checked;

    // UX: disable pendant requête
    checkbox.disabled = true;

    try {
      const url = this.buildToggleUrl(id);

      const form = new URLSearchParams();
      form.set("checked", checked ? "1" : "0");

      const res = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          ...(this.csrfHeader()),
        },
        body: form.toString(),
      });

      if (!res.ok) {
        // rollback
        checkbox.checked = !checked;
        console.error("toggle failed", await this.safeJson(res));
        return;
      }

      // feedback visuel simple (ligne grisée si cochée)
      const row = this.findRowForId(id);
      if (row) {
        row.classList.toggle("opacity-50", checked);
      }
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
   * Valider le caddie :
   * - envoie POST validateUrl
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
          "Accept": "application/json",
          ...(this.csrfHeader()),
        },
      });

      const data = await this.safeJson(res);
      if (!res.ok || !data?.ok) {
        console.error("validateCart failed", data);
        return;
      }

      // Retirer de la table toutes les lignes cochées
      this.checkboxTargets.forEach((cb) => {
        if (cb.checked) {
          const id = cb.dataset.shoppingIdParam || cb.getAttribute("data-shopping-id-param");
          const rowId = id || cb.closest("tr")?.dataset?.shoppingRowId;
          const row = rowId ? this.findRowForId(rowId) : cb.closest("tr");
          if (row) row.remove();
        }
      });

      // Si plus aucune ligne -> afficher état vide si tu as un placeholder
      this.refreshValidateButtonState();
      this.refreshCountBadge();
    } catch (e) {
      console.error(e);
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  // -------- Helpers --------

  buildToggleUrl(id) {
    // toggleUrlValue doit contenir "0" comme placeholder d'id
    // ex: "/shopping/0/toggle" => "/shopping/123/toggle"
    return this.toggleUrlValue.replace("/0/", `/${id}/`).replace("0", String(id));
  }

  csrfHeader() {
    // Option 1 : token injecté via data-shopping-csrf-token-value
    if (this.hasCsrfTokenValue && this.csrfTokenValue) {
      return { "X-CSRF-TOKEN": this.csrfTokenValue };
    }

    // Option 2 : meta[name="csrf-token"] si tu l'utilises
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta?.content) {
      return { "X-CSRF-TOKEN": meta.content };
    }

    return {};
  }

  findRowForId(id) {
    // <tr data-shopping-row-id="123">
    return this.element.querySelector(`tr[data-shopping-row-id="${CSS.escape(String(id))}"]`);
  }

  refreshValidateButtonState() {
    if (!this.hasValidateBtnTarget) return;
    const anyChecked = this.checkboxTargets.some((cb) => cb.checked);
    this.validateBtnTarget.disabled = !anyChecked;
  }

  refreshCountBadge() {
    // optionnel: un badge qui affiche le nombre d'items cochés
    if (!this.hasCountBadgeTarget) return;
    const checkedCount = this.checkboxTargets.filter((cb) => cb.checked).length;
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
