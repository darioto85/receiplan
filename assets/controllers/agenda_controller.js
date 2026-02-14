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
    "overlay", // ✅ nouveau
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

    // ✅ scroll auto 1 seule fois
    this.didAutoScroll = false;

    // ✅ bloque les loads auto déclenchés par IntersectionObserver
    this.suppressInfiniteLoad = true;

    this.loadingUp = false;
    this.loadingDown = false;

    this.loadedWeeks = new Set();
    this.loadingWeeks = new Set();

    this.earliestStart = this.initialStartValue;
    this.latestStart = this.initialStartValue;

    // ✅ UI: loader overlay au démarrage
    this.#showOverlay(true);
    this.#showSentinels(false);
    this.#showLoading(false);

    // ✅ charge la semaine courante
    this.#loadWeek(this.initialStartValue, "append")
      .catch((e) => {
        console.error(e);
        this.#showError("Impossible de charger la semaine courante.");
      })
      .finally(() => {
        // ✅ 1) auto-scroll
        this.#scrollToTodayOnce();

        // ✅ 2) seulement après, on met les observers
        this.#setupObservers();

        // ✅ 3) on autorise l'infinite scroll uniquement après un scroll utilisateur
        this.#enableInfiniteLoadAfterUserScroll();

        // ✅ 4) on masque le loader overlay et on affiche les sentinels
        this.#showOverlay(false);
        this.#showSentinels(true);
      });
  }

  disconnect() {
    this.topObserver?.disconnect();
    this.bottomObserver?.disconnect();
    this.scrollerTarget?.removeEventListener("scroll", this._onFirstUserScroll);
  }

  #setupObservers() {
    const rootEl = this.scrollerTarget;

    const opts = {
      root: rootEl,
      threshold: 0.1,
      rootMargin: "250px 0px 250px 0px",
    };

    this.topObserver = new IntersectionObserver((entries) => {
      if (this.suppressInfiniteLoad) return;
      if (entries.some((e) => e.isIntersecting)) this.#loadPreviousWeek();
    }, opts);

    this.bottomObserver = new IntersectionObserver((entries) => {
      if (this.suppressInfiniteLoad) return;
      if (entries.some((e) => e.isIntersecting)) this.#loadNextWeek();
    }, opts);

    this.topObserver.observe(this.topSentinelTarget);
    this.bottomObserver.observe(this.bottomSentinelTarget);
  }

  #enableInfiniteLoadAfterUserScroll() {
    // ✅ dès que l'user touche au scroll, on autorise les loads infinis
    const scroller = this.scrollerTarget;

    this._onFirstUserScroll = () => {
      this.suppressInfiniteLoad = false;
      scroller.removeEventListener("scroll", this._onFirstUserScroll);
      console.log("[agenda] infinite load enabled (user scrolled)");
    };

    scroller.addEventListener("scroll", this._onFirstUserScroll, { passive: true });
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

  #recomputeBoundsFromDom() {
    const weeks = Array.from(this.element.querySelectorAll("[data-week-start]"))
      .map((el) => el.getAttribute("data-week-start"))
      .filter(Boolean);

    if (weeks.length === 0) return;

    weeks.sort();
    this.earliestStart = weeks[0];
    this.latestStart = weeks[weeks.length - 1];
  }

  #scrollToTodayOnce() {
    if (this.didAutoScroll) return;
    this.didAutoScroll = true;

    const today = this.#getTodayKey();
    if (!today) return;

    const scroller = this.scrollerTarget;

    const dayEl =
      this.element.querySelector(`[data-day="${today}"]`) ||
      this.element.querySelector(`[data-day-key="${today}"]`) ||
      this.element.querySelector(`[data-is-today="1"]`);

    if (!dayEl) {
      console.log("[agenda] today not found in DOM:", today);
      return;
    }

    // ✅ 2 frames pour être safe (layout + fonts/images)
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        const offset = 12;
        const top = dayEl.offsetTop - offset;

        scroller.scrollTo({
          top: Math.max(0, top),
          behavior: "auto", // 👈 important: pas de smooth => pas de “décalage” visuel
        });
      });
    });
  }

  #getTodayKey() {
    if (this.hasTodayValue && this.todayValue) return this.todayValue;
    const dt = new Date();
    const yyyy = dt.getFullYear();
    const mm = String(dt.getMonth() + 1).padStart(2, "0");
    const dd = String(dt.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
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
    // (tu peux le garder, mais on ne l'utilise plus vraiment)
    if (!this.hasLoadingTarget) return;
    this.loadingTarget.classList.toggle("d-none", !show);
  }

  #showError(message) {
    if (!this.hasErrorTarget) return;
    this.errorTarget.textContent = message;
    this.errorTarget.classList.remove("d-none");
  }

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
