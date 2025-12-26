import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    url: String,
    csrf: String,
  };

  static targets = ["container", "button"];

  async refresh(event) {
    event?.preventDefault();
    event?.stopPropagation();

    if (!this.urlValue) {
      console.error("[meal-refresh] missing url");
      return;
    }

    const currentWrapper = this.element.closest("[data-meal-id]");
    const currentMealId = currentWrapper?.getAttribute("data-meal-id") || null;

    this.#setLoading(true);

    try {
      const res = await fetch(this.urlValue, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": this.csrfValue || "",
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        body: JSON.stringify({}),
      });

      const raw = await res.text();

      let data = {};
      try {
        data = raw ? JSON.parse(raw) : {};
      } catch (err) {
        console.error("[meal-refresh] Réponse non-JSON:", raw);
        throw new Error("Réponse serveur invalide (JSON attendu).");
      }

      if (!res.ok) {
        throw new Error(data.message || "Erreur lors du rafraîchissement.");
      }

      // bloc principal
      if (data.mealId && data.html) {
        this.#replaceMealBlock(Number(data.mealId), data.html, currentWrapper, currentMealId);
      }

      // optionnel: updates[]
      if (Array.isArray(data.updates) && data.updates.length > 0) {
        console.log("[meal-refresh] applying updates:", data.updates.map((u) => u.mealId));
        for (const u of data.updates) {
          if (!u?.mealId || !u?.html) continue;
          this.#replaceMealBlock(Number(u.mealId), u.html);
        }
      }
    } catch (e) {
      console.error(e);
      alert(e?.message || "Erreur");
    } finally {
      this.#setLoading(false);
    }
  }

  #replaceMealBlock(mealId, html, currentWrapper = null, currentMealId = null) {
    let target = null;

    if (currentWrapper && currentMealId && String(mealId) === String(currentMealId)) {
      target = currentWrapper;
    } else {
      target = document.querySelector(`[data-meal-id="${mealId}"]`);
    }

    if (!target) {
      console.warn(`[meal-refresh] update ignored: [data-meal-id="${mealId}"] not found in DOM`);
      return;
    }

    const node = this.#parseHtmlRoot(html);
    if (!node) return;

    target.replaceWith(node);
  }

  #parseHtmlRoot(html) {
    const tpl = document.createElement("template");
    tpl.innerHTML = String(html || "").trim();
    return tpl.content.firstElementChild;
  }

  #setLoading(isLoading) {
    if (this.hasButtonTarget) {
      this.buttonTarget.disabled = isLoading;
    }
  }
}
