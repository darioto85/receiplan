// assets/controllers/recipe_wizard_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    saveDraftUrl: String,
    recipeId: Number,     // présent sur edit.html.twig
    previewUrl: String,   // présent sur edit.html.twig
  };

  static targets = [
    "step1",
    "step2",
    "nameInput",
    "step1Error",
    "stepHint",
  ];

  connect() {
    // Si on est sur edit, on a step2 dans le DOM.
    // Par défaut on laisse step1 visible (c’est ce que fait le twig).
    this._setHint(1);
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

    // Sur edit, on passe recipeId pour update
    if (this.hasRecipeIdValue && this.recipeIdValue) {
      formData.append("recipeId", String(this.recipeIdValue));
    }

    try {
      const res = await fetch(this.saveDraftUrlValue, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
        body: formData,
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        const msg = data?.message || "Impossible d’enregistrer le brouillon.";
        this._showError(msg);
        return;
      }

      // Cas création (new.html.twig) : on redirige vers /wizard/{id}
      if (!this.hasRecipeIdValue || !this.recipeIdValue) {
        if (data?.editUrl) {
          window.location.href = data.editUrl;
          return;
        }
        // fallback
        window.location.reload();
        return;
      }

      // Cas édition : on avance en step2 avec fondu
      this.goStep2();

    } catch (e) {
      this._showError("Erreur réseau. Réessaie.");
    }
  }

  goStep1() {
    this._showStep(1);
  }

  goStep2() {
    this._showStep(2);
  }

  _showStep(stepNumber) {
    // Step 1 toujours présent
    if (stepNumber === 1) {
      this.step1Target.classList.remove("is-hidden");
      if (this.hasStep2Target) this.step2Target.classList.add("is-hidden");
      this._setHint(1);
      // focus UX
      this.nameInputTarget?.focus?.();
      return;
    }

    // Step 2 (uniquement sur edit)
    if (stepNumber === 2 && this.hasStep2Target) {
      this.step1Target.classList.add("is-hidden");
      this.step2Target.classList.remove("is-hidden");
      this._setHint(2);
      return;
    }
  }

  _setHint(step) {
    if (!this.hasStepHintTarget) return;

    if (step === 1) {
      this.stepHintTarget.textContent = "Étape 1/3 — Nom";
    } else if (step === 2) {
      this.stepHintTarget.textContent = "Étape 2/3 — Ingrédients";
    } else if (step === 3) {
      this.stepHintTarget.textContent = "Étape 3/3 — Preview";
    }
  }

  _showError(message) {
    if (!this.hasStep1ErrorTarget) return;
    this.step1ErrorTarget.innerHTML = `
      <div class="alert alert-danger mb-0">${this._escapeHtml(message)}</div>
    `;
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
