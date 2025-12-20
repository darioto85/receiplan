import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    urlValidate: String,
    urlCancel: String,
    csrf: String,
  };

  async validate() {
    await this.#send(this.urlValidateValue);
  }

  async cancel() {
    await this.#send(this.urlCancelValue);
  }

  async #send(url) {
    try {
      const res = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": this.csrfValue,
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({}),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        throw new Error(data.message || "Erreur lors de la mise à jour.");
      }

      window.location.reload();
    } catch (e) {
      console.error(e);
      alert(e.message || "Erreur");
    }
  }
}
