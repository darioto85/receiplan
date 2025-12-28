import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    weekUrl: String,
    initialStart: String, // lundi YYYY-MM-DD
  };

  static targets = [
    "scroller",
    "list",
    "topSentinel",
    "bottomSentinel",
    "loading",
    "error",
  ];

  connect() {
    console.log("[agenda] connected", {
      weekUrl: this.weekUrlValue,
      initialStart: this.initialStartValue,
    });

    if (!this.weekUrlValue || !this.initialStartValue) {
      this.#showError("Configuration agenda invalide (URL ou date manquante).");
      return;
    }

    this.loadingUp = false;
    this.loadingDown = false;

    // ✅ anti double load
    this.loadedWeeks = new Set();
    this.loadingWeeks = new Set();

    // ✅ s’assurer que initialStart est bien stable (format YYYY-MM-DD)
    this.earliestStart = this.initialStartValue;
    this.latestStart = this.initialStartValue;

    // ✅ charge d'abord la semaine courante, puis seulement après on observe
    this.#showLoading(true);
    this.#loadWeek(this.initialStartValue, "append")
      .catch((e) => {
        console.error(e);
        this.#showError("Impossible de charger la semaine courante.");
      })
      .finally(() => {
        this.#showLoading(false);
        this.#setupObservers();
      });
  }

  disconnect() {
    this.topObserver?.disconnect();
    this.bottomObserver?.disconnect();
  }

  #setupObservers() {
    const rootEl = this.scrollerTarget;

    const opts = {
      root: rootEl,
      threshold: 0.1,
      rootMargin: "250px 0px 250px 0px",
    };

    this.topObserver = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) this.#loadPreviousWeek();
    }, opts);

    this.bottomObserver = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) this.#loadNextWeek();
    }, opts);

    this.topObserver.observe(this.topSentinelTarget);
    this.bottomObserver.observe(this.bottomSentinelTarget);
  }

  async #loadPreviousWeek() {
    if (this.loadingUp) return;
    this.loadingUp = true;

    const prev = this.#addDaysUtc(this.earliestStart, -7);

    const scroller = this.scrollerTarget;
    const beforeHeight = scroller.scrollHeight;
    const beforeTop = scroller.scrollTop;

    await this.#loadWeek(prev, "prepend");

    const afterHeight = scroller.scrollHeight;
    scroller.scrollTop = beforeTop + (afterHeight - beforeHeight);

    this.earliestStart = prev;
    this.loadingUp = false;
  }

  async #loadNextWeek() {
    if (this.loadingDown) return;
    this.loadingDown = true;

    const next = this.#addDaysUtc(this.latestStart, 7);
    await this.#loadWeek(next, "append");
    this.latestStart = next;

    this.loadingDown = false;
  }

  async #loadWeek(start, mode) {
    // ✅ déjà dans le DOM
    if (this.element.querySelector(`[data-week-start="${start}"]`)) {
      this.loadedWeeks.add(start);
      return;
    }

    // ✅ déjà chargé / en cours
    if (this.loadedWeeks.has(start) || this.loadingWeeks.has(start)) {
      return;
    }
    this.loadingWeeks.add(start);

    const url = new URL(this.weekUrlValue, window.location.origin);
    url.searchParams.set("start", start);

    console.log("[agenda] fetch", url.toString());

    let res;
    try {
      res = await fetch(url.toString(), {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
    } catch (e) {
      console.error("[agenda] network error", e);
      this.#showError("Erreur réseau lors du chargement du planning.");
      this.loadingWeeks.delete(start);
      return;
    }

    if (!res.ok) {
      console.error("[agenda] fetch failed", res.status);
      this.#showError("Erreur serveur lors du chargement du planning.");
      this.loadingWeeks.delete(start);
      return;
    }

    const html = await res.text();
    const tpl = document.createElement("template");
    tpl.innerHTML = html.trim();

    const node = tpl.content.firstElementChild;
    if (!node) {
      console.error("[agenda] no node parsed from response");
      this.#showError("Réponse invalide du serveur.");
      this.loadingWeeks.delete(start);
      return;
    }

    if (mode === "prepend") this.listTarget.prepend(node);
    else this.listTarget.append(node);

    this.loadingWeeks.delete(start);
    this.loadedWeeks.add(start);

    // ✅ bornes recalculées depuis le DOM (les week-start viennent du backend, donc fiables)
    this.#recomputeBoundsFromDom();
  }

  #recomputeBoundsFromDom() {
    const weeks = Array.from(this.element.querySelectorAll("[data-week-start]"))
      .map((el) => el.getAttribute("data-week-start"))
      .filter(Boolean);

    if (weeks.length === 0) return;

    weeks.sort(); // YYYY-MM-DD -> tri OK
    this.earliestStart = weeks[0];
    this.latestStart = weeks[weeks.length - 1];
  }

  #showLoading(show) {
    if (!this.hasLoadingTarget) return;
    this.loadingTarget.classList.toggle("d-none", !show);
  }

  #showError(message) {
    if (!this.hasErrorTarget) return;
    this.errorTarget.textContent = message;
    this.errorTarget.classList.remove("d-none");
  }

  // ✅ FIX CRITIQUE : calcul date en UTC + format manuel (pas de toISOString().slice)
  #addDaysUtc(yyyyMmDd, days) {
    const [y, m, d] = yyyyMmDd.split("-").map((x) => parseInt(x, 10));
    const dt = new Date(Date.UTC(y, m - 1, d)); // UTC midnight
    dt.setUTCDate(dt.getUTCDate() + days);

    const yy = dt.getUTCFullYear();
    const mm = String(dt.getUTCMonth() + 1).padStart(2, "0");
    const dd = String(dt.getUTCDate()).padStart(2, "0");
    return `${yy}-${mm}-${dd}`;
  }
}
