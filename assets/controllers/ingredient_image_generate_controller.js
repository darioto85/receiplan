import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = { url: String };

  async generate(event) {
    event.preventDefault();

    this.element.disabled = true;

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

      // simple: reload pour voir l’image (tu peux faire mieux après)
      window.location.reload();
    } finally {
      this.element.disabled = false;
    }
  }
}
