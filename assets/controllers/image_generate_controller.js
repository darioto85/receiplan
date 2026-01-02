import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = {
    url: String,
    baseSrc: String,          // optionnel: data-image-generate-base-src-value sur le wrapper
    refreshParam: { type: String, default: 'v' }, // optionnel
  };

  static targets = ['img'];

  async generate(event) {
    event.preventDefault();

    // ✅ On désactive uniquement le bouton cliqué (pas tout le header)
    const btn = event.currentTarget;
    btn.disabled = true;

    try {
      const res = await fetch(this.urlValue, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
      });

      // Certaines routes peuvent renvoyer 204 ou un body vide
      let data = {};
      const contentType = res.headers.get('content-type') || '';
      if (contentType.includes('application/json')) {
        data = await res.json().catch(() => ({}));
      }

      if (!res.ok) {
        alert(data.error || 'Erreur lors de la génération.');
        return;
      }

      // ✅ Mise à jour de l'image sans refresh
      if (this.hasImgTarget) {
        const img = this.imgTarget;

        // 1) Si le backend renvoie une URL (idéal), on l’utilise
        if (data.url && typeof data.url === 'string') {
          img.src = data.url;
          return;
        }

        // 2) Sinon, on rebuild depuis une base stable
        // Priorité: baseSrc value (wrapper) > baseSrc sur l'img > src actuel sans query
        const base =
          (this.hasBaseSrcValue && this.baseSrcValue) ||
          img.dataset.imageGenerateBaseSrcValue ||
          (img.getAttribute('src') || '').split('?')[0];

        const url = new URL(base, window.location.origin);
        url.searchParams.set(this.refreshParamValue || 'v', String(Date.now()));

        img.src = url.toString();
        return;
      }

      // fallback simple
      window.location.reload();
    } finally {
      btn.disabled = false;
    }
  }
}
