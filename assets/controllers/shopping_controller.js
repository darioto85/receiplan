// assets/controllers/shopping_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    toggleUrl: String,
    validateUrl: String,
    updateQuantityUrl: String,
    csrfToken: String,
  };

  static targets = ["checkbox", "validateBtn", "countBadge"];

  connect() {
    this.refreshValidateButtonState();
    this.refreshCountBadge();
  }

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

      // ✅ IMPORTANT: toggle sur TOUTES les rows (mobile + desktop)
      this.findRowsForId(id).forEach((row) => {
        row.classList.toggle("is-checked", checked);
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

  async remove(event) {
    event?.preventDefault?.();

    const id = event.params.id;
    if (!id) return;

    if (!confirm("Supprimer cet article de la liste ?")) return;

    // ✅ on désactive ce qu'on peut sur toutes les rows
    this.findRowsForId(id).forEach((row) => {
      row.querySelectorAll('[data-shopping-target="checkbox"], input[type="number"]').forEach((el) => {
        el.disabled = true;
      });
    });

    try {
      const data = await this.postQuantity(id, "0");
      if (!data?.ok) {
        console.error("remove failed", data);
        // réactive si échec
        this.findRowsForId(id).forEach((row) => {
          row.querySelectorAll('[data-shopping-target="checkbox"], input[type="number"]').forEach((el) => {
            el.disabled = false;
          });
        });
        return;
      }

      this.findRowsForId(id).forEach((row) => row.remove());
    } catch (e) {
      console.error(e);
      this.findRowsForId(id).forEach((row) => {
        row.querySelectorAll('[data-shopping-target="checkbox"], input[type="number"]').forEach((el) => {
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

      const checkboxes = this.hasCheckboxTarget ? [...this.checkboxTargets] : [];
      const idsToRemove = new Set();

      checkboxes.forEach((cb) => {
        if (!cb.checked) return;
        const id = cb.dataset.shoppingIdParam || cb.getAttribute("data-shopping-id-param");
        if (id) idsToRemove.add(id);
      });

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
