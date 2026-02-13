// assets/controllers/recipe_picker_controller.js
import { Controller } from "@hotwired/stimulus";

// Bootstrap est global (window.bootstrap) via tes assets
export default class extends Controller {
  static values = {
    searchUrl: String,
    assignUrl: String,
    csrf: String,
  };

  static targets = ["query", "results"];

  connect() {
    this.currentDate = null;

    // Modal element (si controller placé sur la modal OU ailleurs)
    this.modalEl =
      this.element.closest(".modal") || document.getElementById("recipePickerModal");

    if (!this.modalEl) {
      console.warn("[recipe-picker] modal element not found (id recipePickerModal)");
      return;
    }

    // init bootstrap modal instance
    this.bsModal = window.bootstrap?.Modal?.getOrCreateInstance(this.modalEl) || null;

    this.abortCtrl = null;
    this.debounceTimer = null;

    // ✅ écoute event global pour ouverture “propre”
    this.onOpenEvent = (e) => {
      const date = e?.detail?.date || null;
      if (typeof date === "string" && date.length > 0) {
        this.openForDate(date);
      } else {
        console.warn("[recipe-picker] kuko:recipe-picker-open missing detail.date");
      }
    };
    window.addEventListener("kuko:recipe-picker-open", this.onOpenEvent);

    // ✅ Quand la modal s'ouvre, on récupère la date depuis la "source of truth" globale
    this.modalEl.addEventListener("show.bs.modal", () => {
      const d = window.__recipePickerDate || null;
      if (typeof d === "string" && d.length > 0) {
        this.currentDate = d;
      }
    });

    // reset à la fermeture
    this.modalEl.addEventListener("hidden.bs.modal", () => {
      this.currentDate = null;
      if (this.hasQueryTarget) this.queryTarget.value = "";
      if (this.hasResultsTarget) {
        this.resultsTarget.innerHTML = `<div class="text-muted small">Tape pour rechercher…</div>`;
      }
      this.#abortInFlight();
      window.__recipePickerDate = null;
    });
  }

  disconnect() {
    if (this.onOpenEvent) {
      window.removeEventListener("kuko:recipe-picker-open", this.onOpenEvent);
    }
  }

  /**
   * Ouverture par event global (propre)
   */
  openForDate(date) {
    if (!date) {
      console.error("[recipe-picker] openForDate missing date");
      return;
    }

    window.__recipePickerDate = date;
    this.currentDate = date;

    if (!this.bsModal) {
      console.error("[recipe-picker] bootstrap modal instance missing");
      alert("Modal non initialisée.");
      return;
    }

    this.bsModal.show();

    queueMicrotask(() => {
      if (this.hasQueryTarget) this.queryTarget.focus();
      this.search();
    });
  }

  /**
   * Ouverture legacy via data-action="recipe-picker#open"
   * data-recipe-picker-date-param="YYYY-MM-DD"
   */
  open(event) {
    const date = event?.params?.date || null;

    if (!date) {
      console.error("[recipe-picker] missing date param");
      alert("Date manquante pour choisir une recette.");
      return;
    }

    this.openForDate(date);
  }

  /**
   * Recherche (debounce sur input).
   * data-action="input->recipe-picker#search"
   */
  search() {
    clearTimeout(this.debounceTimer);
    this.debounceTimer = setTimeout(() => this.#doSearch(), 180);
  }

  async #doSearch() {
    if (!this.searchUrlValue) {
      console.error("[recipe-picker] missing searchUrlValue");
      return;
    }

    const q = this.hasQueryTarget ? String(this.queryTarget.value || "").trim() : "";

    this.#abortInFlight();
    this.abortCtrl = new AbortController();

    try {
      if (this.hasResultsTarget) {
        this.resultsTarget.innerHTML = `<div class="text-muted small">Recherche…</div>`;
      }

      const url = new URL(this.searchUrlValue, window.location.origin);
      url.searchParams.set("query", q);

      const res = await fetch(url.toString(), {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        signal: this.abortCtrl.signal,
      });

      if (!res.ok) {
        const txt = await res.text();
        console.error("[recipe-picker] search failed", res.status, txt);
        throw new Error("Impossible de charger les recettes.");
      }

      const html = await res.text();

      if (this.hasResultsTarget) {
        this.resultsTarget.innerHTML = html;
      }
    } catch (e) {
      if (e?.name === "AbortError") return;
      console.error(e);
      if (this.hasResultsTarget) {
        this.resultsTarget.innerHTML = `<div class="alert alert-danger mb-0">Erreur de chargement.</div>`;
      }
    }
  }

  /**
   * Choix d'une recette depuis la liste de résultats.
   */
  async choose(event) {
    event?.preventDefault();
    event?.stopPropagation();

    if (!this.currentDate && typeof window.__recipePickerDate === "string") {
      this.currentDate = window.__recipePickerDate;
    }

    const recipeIdAttr =
      event?.currentTarget?.dataset?.recipeId ||
      event?.target?.closest?.("[data-recipe-id]")?.dataset?.recipeId ||
      null;

    const recipeId =
      recipeIdAttr && String(recipeIdAttr).match(/^\d+$/) ? Number(recipeIdAttr) : null;

    if (!this.currentDate) {
      console.error("[recipe-picker] no currentDate");
      alert("Date manquante. Ré-ouvre le picker depuis un jour.");
      return;
    }

    if (!recipeId) {
      console.error("[recipe-picker] invalid recipeId", recipeIdAttr);
      alert("Recette invalide.");
      return;
    }

    if (!this.assignUrlValue) {
      console.error("[recipe-picker] missing assignUrlValue");
      alert("URL d’assignation manquante.");
      return;
    }

    this.#setResultsDisabled(true);

    try {
      const res = await fetch(this.assignUrlValue, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": this.csrfValue || "",
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        body: JSON.stringify({
          date: this.currentDate,
          recipeId: recipeId,
        }),
      });

      const raw = await res.text();

      let data = {};
      try {
        data = raw ? JSON.parse(raw) : {};
      } catch (err) {
        console.error("[recipe-picker] non-JSON response:", raw);
        throw new Error("Réponse serveur invalide (JSON attendu).");
      }

      if (!res.ok) {
        throw new Error(data.message || "Erreur lors de l’ajout.");
      }

      if (!data.mealId || !data.html || !data.date) {
        console.error("[recipe-picker] invalid payload:", data);
        throw new Error("Réponse invalide: mealId/html/date manquants.");
      }

      this.#insertMealIntoDay(String(data.date), String(data.html));
      this.#flashAdded();
    } catch (e) {
      console.error(e);
      alert(e?.message || "Erreur");
    } finally {
      this.#setResultsDisabled(false);
    }
  }

  #insertMealIntoDay(dateStr, html) {
    const list = document.querySelector(`[data-day="${dateStr}"] .meal-list`);
    if (!list) {
      console.warn(
        `[recipe-picker] list not found for date ${dateStr} (maybe week not loaded)`
      );
      return;
    }

    const empty = list.querySelector(`[data-day-propose-target="empty"]`);
    if (empty) empty.remove();

    const node = this.#parseHtmlRoot(html);
    if (!node) return;

    list.append(node);
  }

  #parseHtmlRoot(html) {
    const tpl = document.createElement("template");
    tpl.innerHTML = String(html || "").trim();
    return tpl.content.firstElementChild;
  }

  #abortInFlight() {
    try {
      this.abortCtrl?.abort();
    } catch (e) {}
    this.abortCtrl = null;
  }

  #setResultsDisabled(disabled) {
    if (!this.hasResultsTarget) return;
    this.resultsTarget
      .querySelectorAll("button, a, [role='button']")
      .forEach((el) => {
        el.style.pointerEvents = disabled ? "none" : "";
        el.setAttribute("aria-disabled", disabled ? "true" : "false");
      });
  }

  #flashAdded() {
    if (!this.modalEl) return;

    const existing = this.modalEl.querySelector(".rp-picker-flash");
    if (existing) existing.remove();

    const div = document.createElement("div");
    div.className = "rp-picker-flash alert alert-success py-2 px-3 mb-2";
    div.textContent = "Ajouté au planning ✅";
    div.style.position = "sticky";
    div.style.top = "0";
    div.style.zIndex = "5";

    const body = this.modalEl.querySelector(".modal-body");
    if (body) body.prepend(div);

    setTimeout(() => div.remove(), 900);
  }
}
