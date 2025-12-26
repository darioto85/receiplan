import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    urlValidate: String,
    urlCancel: String,
    csrf: String,
  };

  async validate(event) {
    event?.preventDefault();
    event?.stopPropagation();
    await this.#send(this.urlValidateValue);
  }

  async cancel(event) {
    event?.preventDefault();
    event?.stopPropagation();
    await this.#send(this.urlCancelValue);
  }

  async #send(url) {
    console.log("[meal-plan] ajax send", url); // 🔥 preuve que ce JS est bien chargé

    if (!url) {
      console.error("[meal-plan] Missing URL");
      alert("URL manquante (check data-meal-plan-url-...-value)");
      return;
    }

    this.#setDisabled(true);

    try {
      const res = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": this.csrfValue || "",
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        body: JSON.stringify({}),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        throw new Error(data.message || "Erreur lors de la mise à jour.");
      }

      if (!data.mealId || !data.html) {
        throw new Error("Réponse invalide: mealId/html manquants.");
      }

      const currentMealBlock = document.querySelector(
        `[data-meal-id="${data.mealId}"]`
      );

      if (!currentMealBlock) {
        throw new Error(`Impossible de trouver le bloc du repas #${data.mealId} dans le DOM.`);
      }

      const tpl = document.createElement("template");
      tpl.innerHTML = data.html.trim();
      const node = tpl.content.firstElementChild;

      if (!node) {
        throw new Error("Impossible de parser le HTML retourné.");
      }

      currentMealBlock.replaceWith(node);
    } catch (e) {
      console.error(e);
      alert(e?.message || "Erreur");
    } finally {
      this.#setDisabled(false);
    }
  }

  #setDisabled(disabled) {
    this.element.querySelectorAll("button").forEach((btn) => {
      btn.disabled = disabled;
    });
  }
}
