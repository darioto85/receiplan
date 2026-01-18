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
    console.log("[meal-plan] ajax send", url);

    if (!url) {
      console.error("[meal-plan] Missing URL");
      alert("URL manquante (check data-meal-plan-url-...-value)");
      return;
    }

    // Le controller est sur la zone de boutons ; on remonte au wrapper repas
    const currentWrapper = this.element.closest("[data-meal-id]");
    const currentMealId = currentWrapper?.getAttribute("data-meal-id") || null;

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

      const raw = await res.text();

      let data = {};
      try {
        data = raw ? JSON.parse(raw) : {};
      } catch (err) {
        console.error("[meal-plan] Réponse non-JSON:", raw);
        throw new Error("Réponse serveur invalide (JSON attendu).");
      }

      if (!res.ok) {
        throw new Error(data.message || "Erreur lors de la mise à jour.");
      }

      if (!data.mealId || !data.html) {
        console.error("[meal-plan] Payload reçu:", data);
        throw new Error("Réponse invalide: mealId/html manquants.");
      }

      // ✅ date peut être renvoyée par proposeAjax (pour insertion si le repas n'existe pas encore)
      const dateStr = typeof data.date === "string" ? data.date : null;

      // 1) Remplacement (ou insertion) du bloc principal
      this.#replaceMealBlock(
        Number(data.mealId),
        data.html,
        currentWrapper,
        currentMealId,
        dateStr
      );

      // 2) Remplacement des autres blocs (updates[])
      if (Array.isArray(data.updates) && data.updates.length > 0) {
        console.log("[meal-plan] applying updates:", data.updates.map((u) => u.mealId));

        for (const u of data.updates) {
          if (!u?.mealId || !u?.html) continue;

          const uDate = typeof u.date === "string" ? u.date : null;

          this.#replaceMealBlock(Number(u.mealId), u.html, null, null, uDate);
        }
      } else {
        console.log("[meal-plan] no updates[]");
      }
    } catch (e) {
      console.error(e);
      alert(e?.message || "Erreur");
    } finally {
      // Si le bloc a été remplacé, les nouveaux boutons ne sont pas disabled (état par défaut).
      // On réactive quand même si l'élément original est encore là.
      this.#setDisabled(false);
    }
  }

  /**
   * Remplace un bloc existant [data-meal-id="X"].
   * Si le bloc n'existe pas (cas "proposer" => nouveau MealPlan), insertion dans la journée:
   *   [data-day="YYYY-MM-DD"] .meal-list
   */
  #replaceMealBlock(mealId, html, currentWrapper = null, currentMealId = null, dateStr = null) {
    let target = null;

    if (currentWrapper && currentMealId && String(mealId) === String(currentMealId)) {
      target = currentWrapper;
    } else {
      target = document.querySelector(`[data-meal-id="${mealId}"]`);
    }

    const node = this.#parseHtmlRoot(html);
    if (!node) {
      console.warn("[meal-plan] parse failed for mealId", mealId);
      return;
    }

    if (target) {
      target.replaceWith(node);
      return;
    }

    // ✅ Cas nouveau repas: pas dans le DOM, on insère dans la date
    if (dateStr) {
      const list = document.querySelector(`[data-day="${dateStr}"] .meal-list`);
      if (list) {
        list.prepend(node); // ou append, selon ton UX
        return;
      }
      console.warn(`[meal-plan] cannot insert: list not found for date ${dateStr}`);
      return;
    }

    // Très fréquent si le repas n'est pas dans les semaines actuellement chargées en infinite scroll
    console.warn(`[meal-plan] update ignored: [data-meal-id="${mealId}"] not found in DOM`);
  }

  #parseHtmlRoot(html) {
    const tpl = document.createElement("template");
    tpl.innerHTML = String(html || "").trim();
    return tpl.content.firstElementChild;
  }

  #setDisabled(disabled) {
    this.element.querySelectorAll("button").forEach((btn) => {
      btn.disabled = disabled;
    });
  }
}
