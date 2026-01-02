import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = { url: String };
  static targets = ['img'];

  async generate(event) {
    event.preventDefault();

    const btn = this.element;
    btn.disabled = true;

    try {
      const res = await fetch(this.urlValue, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        alert(data.error || "Erreur lors de la génération.");
        return;
      }

      // ✅ si une image cible est fournie, on la "cache-bust" sans reload
      if (this.hasImgTarget) {
        const img = this.imgTarget;
        const src = img.getAttribute('src') || '';
        const sep = src.includes('?') ? '&' : '?';
        img.setAttribute('src', `${src}${sep}v=${Date.now()}`);
        return;
      }

      // fallback simple
      window.location.reload();
    } finally {
      btn.disabled = false;
    }
  }
}
