import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    weekUrl: String,
    initialStart: String, // lundi YYYY-MM-DD
    today: String,        // YYYY-MM-DD
  };

  static targets = [
    "scroller",
    "list",
    "topSentinel",
    "bottomSentinel",
    "loading",
    "error",
    "overlay",
  ];

  connect() {
    console.log("[agenda] connected", {
      weekUrl: this.weekUrlValue,
      initialStart: this.initialStartValue,
      today: this.todayValue,
    });

    if (!this.weekUrlValue || !this.initialStartValue) {
      this.#showError("Configuration agenda invalide (URL ou date manquante).");
      return;
    }

    this.loadingUp = false;
    this.loadingDown = false;

    // anti double load
    this.loadedWeeks = new Set();
    this.loadingWeeks = new Set();

    this.earliestStart = this.initialStartValue;
    this.latestStart = this.initialStartValue;

    // évite triggers observer juste après preload/scroll
    this.ignoreObserverUntil = 0;

    // UI
    this.#showOverlay(true);
    this.#showSentinels(false);
    this.#showLoading(false);

    this.#bootstrap()
      .catch((e) => {
        console.error(e);
        this.#showError("Impossible de charger le planning.");
      })
      .finally(() => {
        this.#showOverlay(false);
        this.#showSentinels(true);
      });
  }

  disconnect() {
    this.topObserver?.disconnect();
    this.bottomObserver?.disconnect();
  }

  async #bootstrap() {
    // 1) semaine courante
    await this.#loadWeek(this.initialStartValue, "append");

    // 2) preload -1 et +1
    const prev = this.#addDaysUtc(this.initialStartValue, -7);
    const next = this.#addDaysUtc(this.initialStartValue, +7);

    // ⚠️ prepend = ajuste le scrollTop pour ne pas “sauter”
    await this.#prependWeekPreserveScroll(prev);

    // append next
    await this.#loadWeek(next, "append");

    // bornes recalculées
    this.#recomputeBoundsFromDom();

    // ✅ 3) scroll auto sur aujourd’hui (après preload, layout plus stable)
    this.#scrollToToday();

    // ✅ 4) ignore observer un court instant (un peu plus large pour éviter un load immédiat)
    this.ignoreObserverUntil = Date.now() + 1200;

    // ✅ 5) infinite scroll (après avoir scrollé)
    this.#setupObservers();
  }


  #setupObservers() {
    const rootEl = this.scrollerTarget;

    const opts = {
      root: rootEl,
      threshold: 0.1,
      rootMargin: "250px 0px 250px 0px",
    };

    this.topObserver = new IntersectionObserver((entries) => {
      if (Date.now() < this.ignoreObserverUntil) return;
      if (entries.some((e) => e.isIntersecting)) this.#loadPreviousWeek();
    }, opts);

    this.bottomObserver = new IntersectionObserver((entries) => {
      if (Date.now() < this.ignoreObserverUntil) return;
      if (entries.some((e) => e.isIntersecting)) this.#loadNextWeek();
    }, opts);

    this.topObserver.observe(this.topSentinelTarget);
    this.bottomObserver.observe(this.bottomSentinelTarget);
  }

  async #loadPreviousWeek() {
    if (this.loadingUp) return;
    this.loadingUp = true;

    const prev = this.#addDaysUtc(this.earliestStart, -7);
    await this.#prependWeekPreserveScroll(prev);

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

  async #prependWeekPreserveScroll(start) {
    const scroller = this.scrollerTarget;
    const beforeHeight = scroller.scrollHeight;
    const beforeTop = scroller.scrollTop;

    await this.#loadWeek(start, "prepend");

    const afterHeight = scroller.scrollHeight;
    scroller.scrollTop = beforeTop + (afterHeight - beforeHeight);

    this.#recomputeBoundsFromDom();
  }

  async #loadWeek(start, mode) {
    if (this.element.querySelector(`[data-week-start="${start}"]`)) {
      this.loadedWeeks.add(start);
      return;
    }
    if (this.loadedWeeks.has(start) || this.loadingWeeks.has(start)) return;
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

    this.#recomputeBoundsFromDom();
  }

  // ✅ scroll robuste (re-tente si le layout bouge encore)
  #scrollToToday() {
    const today = this.#getTodayKey();
    if (!today) return;

    const scroller = this.scrollerTarget;
    const target = this.element.querySelector(`#day-${today}`);
    if (!target) {
      console.log("[agenda] today anchor not found:", `#day-${today}`);
      return;
    }

    const offset = 12;

    const tryScroll = (attempt = 0) => {
      if (attempt > 12) return;

      requestAnimationFrame(() => {
        const scrollerRect = scroller.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();

        // Position du target dans le flux scrollable :
        const desiredTop =
          scroller.scrollTop + (targetRect.top - scrollerRect.top) - offset;

        scroller.scrollTo({
          top: Math.max(0, desiredTop),
          behavior: "auto",
        });

        // Re-check (images/fonts peuvent encore bouger)
        requestAnimationFrame(() => {
          const scrollerRect2 = scroller.getBoundingClientRect();
          const targetRect2 = target.getBoundingClientRect();
          const desiredTop2 =
            scroller.scrollTop + (targetRect2.top - scrollerRect2.top) - offset;

          const delta = Math.abs(desiredTop2 - scroller.scrollTop);
          if (delta > 2) tryScroll(attempt + 1);
        });
      });
    };

    tryScroll(0);
  }


  #getTodayKey() {
    if (this.hasTodayValue && this.todayValue) return this.todayValue;
    const dt = new Date();
    const yyyy = dt.getFullYear();
    const mm = String(dt.getMonth() + 1).padStart(2, "0");
    const dd = String(dt.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
  }

  #recomputeBoundsFromDom() {
    const weeks = Array.from(this.element.querySelectorAll("[data-week-start]"))
      .map((el) => el.getAttribute("data-week-start"))
      .filter(Boolean);

    if (weeks.length === 0) return;

    weeks.sort();
    this.earliestStart = weeks[0];
    this.latestStart = weeks[weeks.length - 1];
  }

  #showOverlay(show) {
    if (!this.hasOverlayTarget) return;
    this.overlayTarget.classList.toggle("d-none", !show);
  }

  #showSentinels(show) {
    if (this.hasTopSentinelTarget) this.topSentinelTarget.classList.toggle("d-none", !show);
    if (this.hasBottomSentinelTarget) this.bottomSentinelTarget.classList.toggle("d-none", !show);
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

  // calcul date en UTC + format manuel
  #addDaysUtc(yyyyMmDd, days) {
    const [y, m, d] = yyyyMmDd.split("-").map((x) => parseInt(x, 10));
    const dt = new Date(Date.UTC(y, m - 1, d));
    dt.setUTCDate(dt.getUTCDate() + days);

    const yy = dt.getUTCFullYear();
    const mm = String(dt.getUTCMonth() + 1).padStart(2, "0");
    const dd = String(dt.getUTCDate()).padStart(2, "0");
    return `${yy}-${mm}-${dd}`;
  }
}
