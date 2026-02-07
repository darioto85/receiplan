// assets/controllers/recipe_wizard_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    saveDraftUrl: String,
    recipeId: Number,
    previewUrl: String,
  };

  static targets = [
    "step1",
    "step2",
    "step3",
    "nameInput",
    "step1Error",
    "stepHint",
    // ✅ header button swap (step3 => retour ingrédients)
    "headerToStep1",
    "headerToStep2",
  ];

  connect() {
    const params = new URLSearchParams(window.location.search);
    const step = params.get("step");

    if (step === "3" && this.hasStep3Target) {
      this._showStep(3);
    } else if (step === "2" && this.hasStep2Target) {
      this._showStep(2);
    } else {
      this._showStep(1);
    }
  }

  async submitStep1(event) {
    event.preventDefault();

    const name = (this.nameInputTarget?.value || "").trim();
    this._clearError();

    if (!name) {
      this._showError("Le nom est obligatoire.");
      return;
    }

    const formData = new FormData();
    formData.append("name", name);

    if (this.hasRecipeIdValue && this.recipeIdValue) {
      formData.append("recipeId", String(this.recipeIdValue));
    }

    try {
      const res = await fetch(this.saveDraftUrlValue, {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        body: formData,
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        this._showError(data?.message || "Impossible d’enregistrer.");
        return;
      }

      // ✅ Création : redirect vers step2 (URL renvoyée par le controller)
      if (!this.hasRecipeIdValue || !this.recipeIdValue) {
        if (data?.editUrl) {
          window.location.href = data.editUrl;
          return;
        }
        window.location.reload();
        return;
      }

      // ✅ Édition : passe directement step2 (sans reload)
      this.goStep2();
    } catch (e) {
      this._showError("Erreur réseau. Réessaie.");
    }
  }

  goStep1() {
    this._showStep(1);
    this._setUrlStep("1");
  }

  goStep2() {
    this._showStep(2);
    this._setUrlStep("2");
  }

  goStep3() {
    this._showStep(3);
    this._setUrlStep("3");
  }

  _setUrlStep(step) {
    try {
      const url = new URL(window.location.href);
      url.searchParams.set("step", step);
      window.history.replaceState({}, "", url.toString());
    } catch (e) {
      // ignore
    }
  }

  _showStep(stepNumber) {
    if (this.hasStep1Target) this.step1Target.classList.add("is-hidden");
    if (this.hasStep2Target) this.step2Target.classList.add("is-hidden");
    if (this.hasStep3Target) this.step3Target.classList.add("is-hidden");

    // ✅ swap bouton header selon étape
    this._toggleHeaderButtons(stepNumber);

    if (stepNumber === 1 && this.hasStep1Target) {
      this.step1Target.classList.remove("is-hidden");
      this._setHint(1);
      this.nameInputTarget?.focus?.();
      return;
    }

    if (stepNumber === 2 && this.hasStep2Target) {
      this.step2Target.classList.remove("is-hidden");
      this._setHint(2);
      return;
    }

    if (stepNumber === 3 && this.hasStep3Target) {
      this.step3Target.classList.remove("is-hidden");
      this._setHint(3);
      return;
    }
  }

  _toggleHeaderButtons(stepNumber) {
    // step 3 => bouton "retour ingrédients", sinon "modifier le nom"
    if (!this.hasHeaderToStep1Target || !this.hasHeaderToStep2Target) return;

    if (stepNumber === 3) {
      this.headerToStep1Target.classList.add("is-hidden");
      this.headerToStep2Target.classList.remove("is-hidden");
    } else {
      this.headerToStep1Target.classList.remove("is-hidden");
      this.headerToStep2Target.classList.add("is-hidden");
    }
  }

  _setHint(step) {
    if (!this.hasStepHintTarget) return;

    if (step === 1) this.stepHintTarget.textContent = "Étape 1/4 — Nom";
    if (step === 2) this.stepHintTarget.textContent = "Étape 2/4 — Ingrédients";
    if (step === 3) this.stepHintTarget.textContent = "Étape 3/4 — Étapes";
    if (step === 4) this.stepHintTarget.textContent = "Étape 4/4 — Récapitulatif";
  }

  _showError(message) {
    if (!this.hasStep1ErrorTarget) return;
    this.step1ErrorTarget.innerHTML = `<div class="alert alert-danger mb-0">${this._escapeHtml(
      message
    )}</div>`;
  }

  _clearError() {
    if (!this.hasStep1ErrorTarget) return;
    this.step1ErrorTarget.innerHTML = "";
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
