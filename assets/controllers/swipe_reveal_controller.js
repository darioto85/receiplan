import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    openClass: { type: String, default: "is-revealed" },
    width: { type: Number, default: 68 },      // doit matcher le CSS
    threshold: { type: Number, default: 40 },  // swipe minimum
  };

  connect() {
    this.startX = 0;
    this.currentX = 0;
    this.dragging = false;

    this.content = this.element.querySelector(".swipe-content");
    if (!this.content) return;

    // Pointer events (marche touch + souris)
    this.onPointerDown = this.pointerDown.bind(this);
    this.onPointerMove = this.pointerMove.bind(this);
    this.onPointerUp = this.pointerUp.bind(this);

    this.content.addEventListener("pointerdown", this.onPointerDown, { passive: true });
    window.addEventListener("pointermove", this.onPointerMove, { passive: false });
    window.addEventListener("pointerup", this.onPointerUp, { passive: true });

    // Tap hors item => referme
    this.onDocDown = (e) => {
      if (!this.element.contains(e.target)) this.close();
    };
    document.addEventListener("pointerdown", this.onDocDown, { passive: true });
  }

  disconnect() {
    if (this.content) this.content.removeEventListener("pointerdown", this.onPointerDown);
    window.removeEventListener("pointermove", this.onPointerMove);
    window.removeEventListener("pointerup", this.onPointerUp);
    document.removeEventListener("pointerdown", this.onDocDown);
  }

  pointerDown(e) {
    // ne pas swiper quand on interagit avec un input/checkbox
    const tag = e.target?.tagName?.toLowerCase();
    if (tag === "input" || tag === "button" || tag === "select" || tag === "textarea") return;

    this.dragging = true;
    this.startX = e.clientX;
    this.currentX = 0;

    // enlève transition pendant drag
    this.content.style.transition = "none";
  }

  pointerMove(e) {
    if (!this.dragging) return;

    const dx = e.clientX - this.startX;

    // swipe gauche uniquement
    const clamped = Math.max(-this.widthValue, Math.min(0, dx));
    this.currentX = clamped;

    // si on bouge horizontalement, éviter le scroll "parasite"
    if (Math.abs(dx) > 6) e.preventDefault();

    this.content.style.transform = `translateX(${clamped}px)`;
  }

  pointerUp() {
    if (!this.dragging) return;
    this.dragging = false;

    // remet transition
    this.content.style.transition = "";

    // décision open/close
    if (Math.abs(this.currentX) > this.thresholdValue) {
      this.open();
    } else {
      this.close();
    }
  }

  open() {
    this.element.classList.add(this.openClassValue);
    this.content.style.transform = "";
  }

  close() {
    this.element.classList.remove(this.openClassValue);
    this.content.style.transform = "";
  }
}
