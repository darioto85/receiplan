import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    url: String,
    csrf: String,
  };

  static targets = ["button"];

  async remove(event) {
    event?.preventDefault();
    event?.stopPropagation();

    if (!this.urlValue) {
      console.error("[meal-delete] missing url");
      return;
    }

    const wrapper = this.element.closest("[data-meal-id]");
    const mealId = wrapper?.getAttribute("data-meal-id") || null;

    // petit garde-fou UX
    if (!confirm("Supprimer ce repas du planning ?")) {
      return;
    }

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
        console.error("[meal-delete] Réponse non-JSON:", raw);
        throw new Error("Réponse serveur invalide (JSON attendu).");
      }

      if (!res.ok) {
        throw new Error(data.message || "Erreur lors de la suppression.");
      }

      // Suppression DOM
      if (wrapper && mealId && String(data.mealId) === String(mealId)) {
        wrapper.remove();
      } else if (data.mealId) {
        const node = document.querySelector(`[data-meal-id="${data.mealId}"]`);
        node?.remove();
      }
    } catch (e) {
      console.error(e);
      alert(e?.message || "Erreur");
    } finally {
      this.#setLoading(false);
    }
  }

  #setLoading(isLoading) {
    if (this.hasButtonTarget) {
      this.buttonTarget.disabled = isLoading;
    }
  }
}
