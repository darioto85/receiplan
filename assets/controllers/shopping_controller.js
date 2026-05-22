// assets/controllers/shopping_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    toggleUrl: String,
    validateUrl: String,
    clearUrl: String,
    updateQuantityUrl: String,
    updateUnitUrl: String,
    upsertUrl: String,
    csrfToken: String,
  };

  static targets = [
    "checkbox",
    "validateBtn",
    "countBadge",
    "formErrors",
    "desktopTbody",
    "mobileList",
    "emptyState",
  ];

  connect() {
    this.activeCategory = "all";

    this.refreshValidateButtonState();
    this.refreshCountBadge();
    this.refreshEmptyState();
    this._initCategorySlider();
  }

  filterCategory(event) {
    const btn = event.currentTarget;
    const category = btn.dataset.shoppingCategoryFilter || "all";

    this.activeCategory = category;

    this.element.querySelectorAll("[data-shopping-category-filter]").forEach((button) => {
      const isActive = button.dataset.shoppingCategoryFilter === category;

      button.classList.toggle("active", isActive);
      button.classList.toggle("btn-primary", isActive);
      button.classList.toggle("btn-outline-primary", !isActive);
    });

    this._applyCurrentCategoryFilter();
    this.refreshEmptyState();
  }

  async submitGenerate(event) {
    event.preventDefault();

    const form = event.currentTarget;
    const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await fetch(form.action, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
          ...this.csrfHeader(),
        },
        body: new FormData(form),
      });

      const data = await this.safeJson(res);

      if (!res.ok || !data?.ok) {
        console.error("generate failed", data);
        alert(data?.message || "Erreur lors de la génération.");
        return;
      }

      const modalEl = form.closest(".modal");
      if (modalEl && window.bootstrap?.Modal) {
        const instance =
          window.bootstrap.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
        instance.hide();
      }

      window.location.reload();
    } catch (e) {
      console.error(e);
      alert("Erreur réseau.");
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

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
        if (data?.errors && this.hasFormErrorsTarget) {
          this.formErrorsTarget.innerHTML = data.errors;
        } else if (this.hasFormErrorsTarget) {
          this.formErrorsTarget.innerHTML = `<div class="alert alert-danger mb-0">${
            data?.message || "Erreur."
          }</div>`;
        }
        return;
      }

      if (data.htmlDesktop && this.hasDesktopTbodyTarget) {
        this.upsertDomRow(this.desktopTbodyTarget, data.id, data.htmlDesktop);
      }

      if (data.htmlMobile && this.hasMobileListTarget) {
        this.upsertDomRow(this.mobileListTarget, data.id, data.htmlMobile);
      }

      const qtyInput =
        form.querySelector('input[name$="[quantity]"]') ||
        form.querySelector('input[name*="[quantity]"]');
      if (qtyInput) qtyInput.value = "";

      this._applyCurrentCategoryFilter();
      this.refreshValidateButtonState();

      if (typeof data.count !== "undefined") {
        this.setCountBadge(data.count);
      } else {
        this.refreshCountBadge();
      }

      this.refreshEmptyState();
    } catch (e) {
      console.error(e);
      if (this.hasFormErrorsTarget) {
        this.formErrorsTarget.innerHTML = `<div class="alert alert-danger mb-0">Erreur réseau.</div>`;
      }
    }
  }

  upsertDomRow(containerEl, id, html) {
    const selector = `[data-shopping-row-id="${CSS.escape(String(id))}"]`;
    const existing = containerEl.querySelector(selector);

    if (existing) {
      existing.outerHTML = html;
    } else {
      containerEl.insertAdjacentHTML("afterbegin", html);
    }
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
        checkbox.checked = !checked;
        console.error("toggle failed", data);
        return;
      }

      this.findRowsForId(id).forEach((row) => {
        row.classList.toggle("is-checked", checked);
        row.querySelectorAll('[data-shopping-target="checkbox"]').forEach((cb) => {
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
        this.findRowsForId(id).forEach((row) => row.remove());
      } else if (typeof data.quantity !== "undefined") {
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
      this.refreshEmptyState();
    }
  }

  async updateUnit(event) {
    const select = event.currentTarget;
    const id = event.params.id;
    if (!id) return;

    const rawUnit = String(select.value ?? "").trim();
    const previousValue = select.dataset.previousValue ?? "";

    select.disabled = true;

    try {
      const data = await this.postUnit(id, rawUnit);
      if (!data?.ok) {
        select.value = previousValue;
        console.error("updateUnit failed", data);
        return;
      }

      this.findRowsForId(id).forEach((row) => {
        row.querySelectorAll('select[data-action*="shopping#updateUnit"]').forEach((s) => {
          s.value = data.unit;
          s.dataset.previousValue = data.unit;
        });
      });
    } catch (e) {
      select.value = previousValue;
      console.error(e);
    } finally {
      select.disabled = false;
    }
  }

  async remove(event) {
    event?.preventDefault?.();

    const id = event.params.id;
    if (!id) return;

    this.findRowsForId(id).forEach((row) => {
      row
        .querySelectorAll('[data-shopping-target="checkbox"], input[type="number"], select')
        .forEach((el) => {
          el.disabled = true;
        });
    });

    try {
      const data = await this.postQuantity(id, "0");
      if (!data?.ok) {
        console.error("remove failed", data);
        this.findRowsForId(id).forEach((row) => {
          row
            .querySelectorAll('[data-shopping-target="checkbox"], input[type="number"], select')
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
          .querySelectorAll('[data-shopping-target="checkbox"], input[type="number"], select')
          .forEach((el) => {
            el.disabled = false;
          });
      });
    } finally {
      this.refreshValidateButtonState();
      this.refreshCountBadge();
      this.refreshEmptyState();
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

      const idsToRemove = new Set();

      if (this.hasCheckboxTarget) {
        this.checkboxTargets.forEach((cb) => {
          if (!cb.checked) return;
          const id = cb.dataset.shoppingIdParam || cb.getAttribute("data-shopping-id-param");
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
      this.refreshEmptyState();
      if (btn) btn.disabled = false;
    }
  }

  async clearCart(event) {
    event?.preventDefault?.();

    const btn = event.currentTarget;
    if (btn) btn.disabled = true;

    try {
      const res = await fetch(this.clearUrlValue, {
        method: "POST",
        headers: {
          Accept: "application/json",
          ...this.csrfHeader(),
        },
      });

      const data = await this.safeJson(res);

      if (!res.ok || !data?.ok) {
        console.error("clearCart failed", data);
        alert(data?.message || "Erreur lors du vidage de la liste.");
        return;
      }

      this.element.querySelectorAll("[data-shopping-row-id]").forEach((row) => {
        row.remove();
      });

      const modalEl = document.getElementById("shoppingClearModal");

      if (modalEl && window.bootstrap?.Modal) {
        const instance =
          window.bootstrap.Modal.getInstance(modalEl) ||
          new window.bootstrap.Modal(modalEl);

        instance.hide();
      }
    } catch (e) {
      console.error(e);
      alert("Erreur réseau.");
    } finally {
      if (btn) btn.disabled = false;

      this.refreshValidateButtonState();
      this.refreshCountBadge();
      this.refreshEmptyState();
    }
  }

  refreshEmptyState() {
    if (!this.hasEmptyStateTarget) return;

    const hasVisibleRow = this.element.querySelector("[data-shopping-row-id]:not(.d-none)") !== null;

    this.emptyStateTarget.classList.toggle("d-none", hasVisibleRow);
  }

  _applyCurrentCategoryFilter() {
    const category = this.activeCategory || "all";

    this.element.querySelectorAll("[data-shopping-row-id]").forEach((item) => {
      const itemCategory = item.dataset.shoppingCategory || "";
      const shouldShow = category === "all" || itemCategory === category;

      item.classList.toggle("d-none", !shouldShow);
    });
  }

  _initCategorySlider() {
    const slider = this.element.querySelector(".rp-category-filter__scroller");

    if (!slider) return;

    let isDown = false;
    let startX = 0;
    let scrollLeft = 0;

    slider.addEventListener("mousedown", (e) => {
      isDown = true;
      slider.classList.add("is-dragging");

      startX = e.pageX - slider.offsetLeft;
      scrollLeft = slider.scrollLeft;
    });

    slider.addEventListener("mouseleave", () => {
      isDown = false;
      slider.classList.remove("is-dragging");
    });

    slider.addEventListener("mouseup", () => {
      isDown = false;
      slider.classList.remove("is-dragging");
    });

    slider.addEventListener("mousemove", (e) => {
      if (!isDown) return;

      e.preventDefault();

      const x = e.pageX - slider.offsetLeft;
      const walk = (x - startX) * 1.2;

      slider.scrollLeft = scrollLeft - walk;
    });
  }

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

  async postUnit(id, rawUnit) {
    const url = this.buildUrlWithId(this.updateUnitUrlValue, id);

    const form = new URLSearchParams();
    form.set("unit", String(rawUnit).trim());

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

    const ids = new Set();
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