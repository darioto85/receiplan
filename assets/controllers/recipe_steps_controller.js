// assets/controllers/recipe_steps_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    addUrl: String,
  };

  static targets = [
    "content",
    "formError",
    "list",
  ];

  connect() {
    this.editingEl = null;
    this._outsideHandler = this._onOutsideClick.bind(this);
  }

  disconnect() {
    document.removeEventListener("mousedown", this._outsideHandler);
  }

  // =========================
  // ADD
  // =========================
  async submitAdd(event) {
    event.preventDefault();

    this._clearError();

    const content = (this.contentTarget?.value || "").trim();
    if (!content) {
      this._showError("Le texte est obligatoire.");
      return;
    }

    const body = new URLSearchParams();
    body.append("content", content);

    try {
      const res = await fetch(this.addUrlValue, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || data?.status !== "ok") {
        this._showError(data?.message || "Impossible d’ajouter l’étape.");
        return;
      }

      const tmp = document.createElement("div");
      tmp.innerHTML = (data.html || "").trim();
      const newItem = tmp.firstElementChild;
      if (newItem) {
        this.listTarget.appendChild(newItem);
      }

      this.contentTarget.value = "";
    } catch (e) {
      this._showError("Erreur réseau. Réessaie.");
    }
  }

  // =========================
  // DELETE
  // =========================
  async deleteItem(event) {
    event.preventDefault();
    this._clearError();

    const form = event.currentTarget;
    const url = form.getAttribute("action");
    const token = form.querySelector('input[name="_token"]')?.value || "";

    const body = new URLSearchParams();
    body.append("_token", token);

    try {
      const res = await fetch(url, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || data?.status !== "ok") {
        this._showError(data?.message || "Impossible de supprimer.");
        return;
      }

      const item = form.closest("[data-recipe-step-id]");
      item?.remove();
    } catch (e) {
      this._showError("Erreur réseau.");
    }
  }

  // =========================
  // INLINE EDIT
  // =========================

  startEdit(event) {
    const item = event.currentTarget.closest("[data-recipe-step-id]");
    if (!item) return;

    // si clic sur un bouton (supprimer), on ignore
    if (event.target.closest("button, form, a")) return;

    // déjà en édition
    if (this.editingEl && this.editingEl === item) return;

    // si une autre ligne est en édition, on la commit
    if (this.editingEl && this.editingEl !== item) {
      this.commitEdit(this.editingEl);
    }

    this.editingEl = item;

    const textEl = item.querySelector("[data-step-text]");
    const editorWrap = item.querySelector("[data-step-editor]");
    const textarea = item.querySelector("[data-step-textarea]");

    if (!textEl || !editorWrap || !textarea) return;

    textarea.value = (textEl.textContent || "").trim();

    textEl.classList.add("d-none");
    editorWrap.classList.remove("d-none");

    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);

    // écouter clic extérieur
    document.addEventListener("mousedown", this._outsideHandler);
  }

  onEditorKeydown(event) {
    if (event.key === "Escape") {
      event.preventDefault();
      const item = event.currentTarget.closest("[data-recipe-step-id]");
      if (item) this.cancelEdit(item);
    }

    // Enter valide (sans créer de saut de ligne) — Shift+Enter garde le retour ligne
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      const item = event.currentTarget.closest("[data-recipe-step-id]");
      if (item) this.commitEdit(item);
    }
  }

  async onEditorBlur(event) {
    const item = event.currentTarget.closest("[data-recipe-step-id]");
    if (!item) return;

    // blur peut arriver quand on clique sur "Supprimer" : on commit quand même
    this.commitEdit(item);
  }

  _onOutsideClick(e) {
    if (!this.editingEl) return;
    if (this.editingEl.contains(e.target)) return; // clic dedans => ignore

    // clic dehors => commit
    this.commitEdit(this.editingEl);
  }

  cancelEdit(item) {
    const textEl = item.querySelector("[data-step-text]");
    const editorWrap = item.querySelector("[data-step-editor]");

    if (textEl) textEl.classList.remove("d-none");
    if (editorWrap) editorWrap.classList.add("d-none");

    this.editingEl = null;
    document.removeEventListener("mousedown", this._outsideHandler);
  }

  async commitEdit(item) {
    const textarea = item.querySelector("[data-step-textarea]");
    const textEl = item.querySelector("[data-step-text]");
    const editorWrap = item.querySelector("[data-step-editor]");
    const url = item.getAttribute("data-step-update-url") || "";
    const token = item.getAttribute("data-step-update-token") || "";

    if (!textarea || !textEl || !editorWrap || !url) {
      this.cancelEdit(item);
      return;
    }

    const newContent = (textarea.value || "").trim();

    // vide => on refuse et on reste en édition
    if (!newContent) {
      this._showError("Le texte est obligatoire.");
      textarea.focus();
      return;
    }

    // pas de changement => fermer
    const oldContent = (textEl.textContent || "").trim();
    if (newContent === oldContent) {
      textEl.classList.remove("d-none");
      editorWrap.classList.add("d-none");
      this.editingEl = null;
      document.removeEventListener("mousedown", this._outsideHandler);
      return;
    }

    // call ajax update
    const body = new URLSearchParams();
    body.append("_token", token);
    body.append("content", newContent);

    try {
      const res = await fetch(url, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || data?.status !== "ok") {
        this._showError(data?.message || "Impossible de mettre à jour.");
        textarea.focus();
        return;
      }

      // UI update
      textEl.textContent = data.content ?? newContent;
      textEl.classList.remove("d-none");
      editorWrap.classList.add("d-none");

      this.editingEl = null;
      document.removeEventListener("mousedown", this._outsideHandler);
    } catch (e) {
      this._showError("Erreur réseau.");
      textarea.focus();
    }
  }

  // =========================
  // helpers
  // =========================
  _showError(message) {
    if (!this.hasFormErrorTarget) return;
    this.formErrorTarget.innerHTML = `<div class="alert alert-danger mb-0">${this._escapeHtml(message)}</div>`;
  }

  _clearError() {
    if (!this.hasFormErrorTarget) return;
    this.formErrorTarget.innerHTML = "";
  }

  _escapeHtml(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }
}
