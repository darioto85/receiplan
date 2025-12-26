import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    url: String,
    date: String, // YYYY-MM-DD
    csrf: String,
  };

  static targets = ["meals", "empty", "button"];

  async propose(event) {
    event?.preventDefault();

    if (!this.urlValue || !this.dateValue) {
      console.error("[day-propose] missing url/date");
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
        body: JSON.stringify({ date: this.dateValue }),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.message || "Erreur lors de la proposition.");
      }

      // Cache le placeholder "Aucun repas"
      if (this.hasEmptyTarget) {
        this.emptyTarget.remove();
      }

      // Injecte le HTML du repas directement dans ce jour
      if (data.html) {
        const tpl = document.createElement("template");
        tpl.innerHTML = data.html.trim();
        const node = tpl.content.firstElementChild;
        if (node) this.mealsTarget.append(node);
      }
    } catch (e) {
      console.error(e);
      alert(e?.message || "Erreur");
    } finally {
      this.#setLoading(false);
    }
  }

  #setLoading(isLoading) {
    if (this.hasButtonTarget) this.buttonTarget.disabled = isLoading;
  }
}
