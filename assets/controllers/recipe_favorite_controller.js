// assets/controllers/recipe_favorite_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    url: String,
    token: String,
    iconOn: String,
    iconOff: String,
  };

  static targets = ["img"];

  async toggle(event) {
    event.preventDefault();

    const body = new URLSearchParams();
    body.append("_token", this.tokenValue);

    try {
      const res = await fetch(this.urlValue, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || data?.status !== "ok") {
        console.error(data?.message || "Impossible de modifier le favori.");
        return;
      }

      // ✅ conversion robuste : true/false, 1/0, "1"/"0", "true"/"false"
      const isFav =
        data.favorite === true ||
        data.favorite === 1 ||
        data.favorite === "1" ||
        data.favorite === "true";

      const img =
        (this.hasImgTarget ? this.imgTarget : null) ||
        this.element.querySelector("img");

      if (img) {
        const nextSrc = isFav ? this.iconOnValue : this.iconOffValue;

        // ✅ cache-buster pour forcer le refresh visuel
        const bust = `v=${Date.now()}`;
        img.src = nextSrc.includes("?") ? `${nextSrc}&${bust}` : `${nextSrc}?${bust}`;
      }

      // a11y + tooltip
      this.element.setAttribute("aria-pressed", isFav ? "true" : "false");
      this.element.setAttribute(
        "title",
        isFav ? "Retirer des favoris" : "Ajouter aux favoris"
      );
      this.element.setAttribute(
        "aria-label",
        isFav ? "Retirer des favoris" : "Ajouter aux favoris"
      );
    } catch (e) {
      console.error("Erreur réseau favorite", e);
    }
  }
}
