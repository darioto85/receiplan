import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    url: String,
    csrf: String,
  };

  static targets = ["container", "button"];

  async refresh(event) {
    event?.preventDefault();

    if (!this.urlValue) {
      console.error("[meal-refresh] missing url");
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

      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.message || "Erreur lors du rafraîchissement.");
      }

      if (data.html) {
        const tpl = document.createElement("template");
        tpl.innerHTML = data.html.trim();
        const node = tpl.content.firstElementChild;

        if (node && this.hasContainerTarget) {
          this.containerTarget.replaceWith(node);
        }
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
