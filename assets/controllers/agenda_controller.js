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

    this.earliestStart = this.initialStartValue;
    this.latestStart = this.initialStartValue;

    this.#showLoading(true);
    this.#loadWeek(this.initialStartValue, "append")
      .catch((e) => {
        console.error(e);
        this.#showError("Impossible de charger la semaine courante.");
      })
      .finally(() => {
        this.#showLoading(false);
      });

    // ✅ IMPORTANT : root = scroller (scroll interne)
    const rootEl = this.scrollerTarget;

    this.topObserver = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          this.#loadPreviousWeek();
        }
      },
      { root: rootEl, threshold: 0.1 }
    );

    this.bottomObserver = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          this.#loadNextWeek();
        }
      },
      { root: rootEl, threshold: 0.1 }
    );

    this.topObserver.observe(this.topSentinelTarget);
    this.bottomObserver.observe(this.bottomSentinelTarget);
  }

  disconnect() {
    this.topObserver?.disconnect();
    this.bottomObserver?.disconnect();
  }

  async #loadPreviousWeek() {
    if (this.loadingUp) return;
    this.loadingUp = true;

    const prev = this.#addDays(this.earliestStart, -7);

    // ✅ recalage par rapport au scroller (pas window)
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

    const next = this.#addDays(this.latestStart, 7);
    await this.#loadWeek(next, "append");
    this.latestStart = next;

    this.loadingDown = false;
  }

  async #loadWeek(start, mode) {
    if (this.element.querySelector(`[data-week-start="${start}"]`)) {
      return;
    }

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
      return;
    }

    if (!res.ok) {
      console.error("[agenda] fetch failed", res.status);
      this.#showError("Erreur serveur lors du chargement du planning.");
      return;
    }

    const html = await res.text();
    const tpl = document.createElement("template");
    tpl.innerHTML = html.trim();

    const node = tpl.content.firstElementChild;
    if (!node) {
      console.error("[agenda] no node parsed from response");
      this.#showError("Réponse invalide du serveur.");
      return;
    }

    if (mode === "prepend") {
      this.listTarget.prepend(node);
    } else {
      this.listTarget.append(node);
    }
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

  #addDays(yyyyMmDd, days) {
    const d = new Date(yyyyMmDd + "T00:00:00");
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
  }
}
