// assets/controllers/shopping_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    toggleUrl: String,
    validateUrl: String,
    updateQuantityUrl: String,
    upsertUrl: String,
    csrfToken: String,
  };

  static targets = [
    "checkbox",
    "validateBtn",
    "countBadge",

    // ✅ NEW (quick add)
    "formErrors",
    "desktopTbody",
    "mobileList",
  ];

  connect() {
    this.refreshValidateButtonState();
    this.refreshCountBadge();
  }

  // =========================
  // ✅ NEW: Quick Add (Upsert)
  // =========================
  async submitUpsert(event) {
    event.preventDefault();

    const form = event.currentTarget;
    const url = form.getAttribute("action") || this.upsertUrlValue;

    if (this.hasFormErrorsTarget) this.formErrorsTarget.innerHTML = "";

    try {
      const res = await fetch(url, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
          ...this.csrfHeader(),
        },
        body: new FormData(form),
      });

      const data = await this.safeJson(res);

      if (!res.ok || data?.status !== "ok") {
        // 422 => erreurs twig (comme stock)
        if (data?.errors && this.hasFormErrorsTarget) {
          this.formErrorsTarget.innerHTML = data.errors;
        } else if (this.hasFormErrorsTarget) {
          this.formErrorsTarget.innerHTML =
            `<div class="alert alert-danger mb-0">${data?.message || "Erreur."}</div>`;
        }
        return;
      }

      // ✅ Desktop: prepend/replace
      if (data.htmlDesktop && this.hasDesktopTbodyTarget) {
        this.upsertDomRow(this.desktopTbodyTarget, data.id, data.htmlDesktop);
      }

      // ✅ Mobile: prepend/replace
      if (data.htmlMobile && this.hasMobileListTarget) {
        this.upsertDomRow(this.mobileListTarget, data.id, data.htmlMobile);
      }

      // ✅ reset qty input (on garde l’ingrédient sélectionné)
      const qtyInput =
        form.querySelector('input[name$="[quantity]"]') ||
        form.querySelector('input[name*="[quantity]"]');
      if (qtyInput) qtyInput.value = "";

      // ✅ refresh UI
      this.refreshValidateButtonState();

      // serveur renvoie count -> sinon recalcul DOM
      if (typeof data.count !== "undefined") {
        this.setCountBadge(data.count);
      } else {
        this.refreshCountBadge();
      }
    } catch (e) {
      console.error(e);
      if (this.hasFormErrorsTarget) {
        this.formErrorsTarget.innerHTML =
          `<div class="alert alert-danger mb-0">Erreur réseau.</div>`;
      }
    }
  }

  upsertDomRow(containerEl, id, html) {
    const selector = `[data-shopping-row-id="${CSS.escape(String(id))}"]`;
    const existing = containerEl.querySelector(selector);
    if (existing) {
      existing.outerHTML = html; // replace
    } else {
      containerEl.insertAdjacentHTML("afterbegin", html); // prepend
    }
  }

  // =========================
  // Existing features
  // =========================
  async toggle(event) {
    const checkbox = event.currentTarget;
    const id = event.params.id;
    if (!id) return;

    const checked = checkbox.checked;

    const label = this.findLabelForCheckbox(checkbox);
    if (label) label.classList.add("is-loading");

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

      // ✅ IMPORTANT: synchro toutes les rows (mobile + desktop)
      this.findRowsForId(id).forEach((row) => {
        row.classList.toggle("is-checked", checked);

        // synchro checkbox dans l’autre vue
        row
          .querySelectorAll('[data-shopping-target="checkbox"]')
          .forEach((cb) => {
            cb.checked = checked;
          });
      });
    } catch (e) {
      checkbox.checked = !checked;
      console.error(e);
    } finally {
      if (label) label.classList.remove("is-loading");
      this.refreshValidateButtonState();
      this.refreshCountBadge();
    }
  }

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
        // ✅ retire mobile + desktop
        this.findRowsForId(id).forEach((row) => row.remove());
      } else if (typeof data.quantity !== "undefined") {
        // ✅ met à jour les inputs dans les 2 vues
        this.findRowsForId(id).forEach((row) => {
          row.querySelectorAll('input[type="number"]').forEach((n) => {
            n.value = data.quantity;
          });
        });
      }
    } catch (e) {
      console.error(e);
    } finally {
      input.disabled = false;
      this.refreshValidateButtonState();
      this.refreshCountBadge();
    }
  }

  async remove(event) {
    event?.preventDefault?.();

    const id = event.params.id;
    if (!id) return;

    if (!confirm("Supprimer cet article de la liste ?")) return;

    // ✅ on désactive ce qu'on peut sur toutes les rows
    this.findRowsForId(id).forEach((row) => {
      row
        .querySelectorAll('[data-shopping-target="checkbox"], input[type="number"]')
        .forEach((el) => {
          el.disabled = true;
        });
    });

    try {
      const data = await this.postQuantity(id, "0");
      if (!data?.ok) {
        console.error("remove failed", data);
        // réactive si échec
        this.findRowsForId(id).forEach((row) => {
          row
            .querySelectorAll('[data-shopping-target="checkbox"], input[type="number"]')
            .forEach((el) => {
              el.disabled = false;
            });
        });
        return;
      }

      this.findRowsForId(id).forEach((row) => row.remove());
    } catch (e) {
      console.error(e);
      this.findRowsForId(id).forEach((row) => {
        row
          .querySelectorAll('[data-shopping-target="checkbox"], input[type="number"]')
          .forEach((el) => {
            el.disabled = false;
          });
      });
    } finally {
      this.refreshValidateButtonState();
      this.refreshCountBadge();
    }
  }

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

      // supprime toutes les lignes cochées (mobile + desktop)
      const idsToRemove = new Set();

      if (this.hasCheckboxTarget) {
        this.checkboxTargets.forEach((cb) => {
          if (!cb.checked) return;
          const id =
            cb.dataset.shoppingIdParam || cb.getAttribute("data-shopping-id-param");
          if (id) idsToRemove.add(id);
        });
      }

      idsToRemove.forEach((id) => {
        this.findRowsForId(id).forEach((row) => row.remove());
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

  findLabelForCheckbox(checkbox) {
    const next = checkbox.nextElementSibling;
    if (next && next.matches("label.rp-check-label")) return next;

    const id = checkbox.id;
    if (id) return this.element.querySelector(`label[for="${CSS.escape(id)}"]`);

    return null;
  }

  buildUrlWithId(templateUrl, id) {
    const t = String(templateUrl);
    if (t.includes("/0/")) return t.replace("/0/", `/${id}/`);
    if (t.endsWith("/0")) return t.replace(/\/0$/, `/${id}`);
    if (t.includes("0")) return t.replace("0", String(id));
    return t;
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
    if (!res.ok) return data ?? { ok: false };
    return data;
  }

  csrfHeader() {
    if (this.hasCsrfTokenValue && this.csrfTokenValue) {
      return { "X-CSRF-TOKEN": this.csrfTokenValue };
    }

    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta?.content) return { "X-CSRF-TOKEN": meta.content };

    return {};
  }

  // ✅ retourne toutes les occurrences (mobile + desktop)
  findRowsForId(id) {
    const safe = CSS.escape(String(id));
    return Array.from(this.element.querySelectorAll(`[data-shopping-row-id="${safe}"]`));
  }

  refreshValidateButtonState() {
    if (!this.hasValidateBtnTarget) return;

    const anyChecked = this.hasCheckboxTarget
      ? this.checkboxTargets.some((cb) => cb.checked)
      : false;

    this.validateBtnTarget.disabled = !anyChecked;
  }

  // ✅ badge = nombre total d'items (pas nombre cochés)
  refreshCountBadge() {
    if (!this.hasCountBadgeTarget) return;

    const ids = new Set();

    // On compte une seule fois par id (car mobile + desktop)
    this.element.querySelectorAll("[data-shopping-row-id]").forEach((el) => {
      const id = el.getAttribute("data-shopping-row-id");
      if (id) ids.add(id);
    });

    this.setCountBadge(ids.size);
  }

  setCountBadge(n) {
    if (!this.hasCountBadgeTarget) return;
    this.countBadgeTarget.textContent = String(n);
  }

  async safeJson(res) {
    try {
      return await res.json();
    } catch {
      return null;
    }
  }
}
