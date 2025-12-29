import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = [
    'fileInput',
    'previewWrapper',
    'previewImage',
    'analyzeButton',
    'resetButton',
    'status',
    'results',
    'resultsList',
    'applyButton',
  ];

  static values = {
    uploadUrl: String,
    applyUrl: String,
  };

  connect() {
    this.file = null;
    this.items = [];
    this._bindFileInput();
    this._resetUi();
  }

  _bindFileInput() {
    this.fileInputTarget.addEventListener('change', () => {
      const file = this.fileInputTarget.files?.[0] || null;
      if (!file) {
        this.reset();
        return;
      }

      // Basic type guard (UX friendly; server is source of truth)
      if (!file.type.startsWith('image/')) {
        this._setStatus('danger', 'Le fichier sélectionné ne semble pas être une image.');
        this.fileInputTarget.value = '';
        return;
      }

      this.file = file;
      this._showPreview(file);
      this.analyzeButtonTarget.disabled = false;
      this.resetButtonTarget.disabled = false;

      // clear previous results
      this._hideResults();
      this._clearStatus();
    });
  }

  _showPreview(file) {
    const url = URL.createObjectURL(file);
    this.previewImageTarget.src = url;
    this.previewWrapperTarget.classList.remove('d-none');
  }

  async analyze() {
    if (!this.file) return;

    if (!this.uploadUrlValue) {
      this._setStatus('danger', "URL d’analyse manquante (data-ticket-scan-upload-url-value).");
      return;
    }

    this._setLoading(true);
    this._setStatus('info', 'Analyse en cours…');

    try {
      const form = new FormData();
      form.append('image', this.file);

      const res = await fetch(this.uploadUrlValue, {
        method: 'POST',
        body: form,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      const data = await res.json().catch(() => null);

      if (!res.ok) {
        const msg = data?.error?.message || 'Impossible d’analyser le ticket pour le moment.';
        this._setStatus('danger', msg);
        this._setLoading(false);
        return;
      }

      // Reset selection state each analysis
      this.items = Array.isArray(data.items)
        ? data.items.map((it) => ({ ...it, _selected: undefined }))
        : [];

      this._renderItems(this.items);

      if (this.items.length === 0) {
        this._setStatus('warning', "Je n’ai rien détecté de fiable sur cette photo. Essaie une photo plus nette.");
      } else {
        this._clearStatus();
      }

      this._setLoading(false);
    } catch (e) {
      this._setStatus('danger', 'Erreur réseau. Vérifie ta connexion et réessaie.');
      this._setLoading(false);
    }
  }

  reset() {
    this.file = null;
    this.items = [];
    this.fileInputTarget.value = '';
    this._resetUi();
  }

  async apply() {
    if (!this.applyUrlValue) {
      this._setStatus('danger', "URL d’ajout manquante (data-ticket-scan-apply-url-value).");
      return;
    }

    const selected = (this.items || []).filter((it) => it._selected);

    // sécurité: ne pas envoyer des lignes incomplètes
    const valid = selected.filter((it) => !this._rowNeedsConfirmation(it));

    if (valid.length === 0) {
      this._setStatus('warning', "Sélectionne au moins une ligne complète (nom + quantité + unité).");
      return;
    }

    this._setStatus('info', 'Ajout au stock…');
    this.applyButtonTarget.disabled = true;

    try {
      const res = await fetch(this.applyUrlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ items: valid }),
      });

      const data = await res.json().catch(() => null);

      if (!res.ok) {
        const msg = data?.error?.message || 'Impossible d’ajouter au stock.';
        this._setStatus('danger', msg);
        this._refreshApplyButtonState();
        return;
      }

      const updated = data?.updated ?? 0;

      if (updated > 0) {
        this._setStatus('success', `${updated} article(s) ajouté(s) au stock ✅`);
        // Option : reset l’UI après succès
        // this.reset();
      } else {
        this._setStatus('warning', "Je n’ai rien ajouté (certaines lignes manquent peut-être de quantité).");
        this._refreshApplyButtonState();
      }
    } catch (e) {
      this._setStatus('danger', 'Erreur réseau. Réessaie.');
      this._refreshApplyButtonState();
    }
  }

  onRowChange(event) {
    const el = event.target;
    const idx = Number(el.dataset.index);
    if (!Number.isFinite(idx) || !this.items?.[idx]) return;

    // Checkbox
    if (el.type === 'checkbox') {
      this.items[idx]._selected = el.checked;
      this._refreshApplyButtonState();
      return;
    }

    const field = el.dataset.field;
    if (!field) return;

    if (field === 'name') {
      this.items[idx].name = el.value.trim();
    } else if (field === 'quantity') {
      const v = el.value;
      this.items[idx].quantity = v === '' ? null : Number(String(v).replace(',', '.'));
    } else if (field === 'unit') {
      this.items[idx].unit = el.value === '' ? null : el.value;
    }

    // recompute needs_confirmation based on completeness
    this.items[idx].needs_confirmation = this._rowNeedsConfirmation(this.items[idx]);

    // Visuel: si l'utilisateur complète une ligne, on réévalue aussi la confiance basse
    this._refreshRowUi(idx);

    this._refreshApplyButtonState();
  }

  _rowNeedsConfirmation(it) {
    if (!it.name || String(it.name).trim() === '') return true;
    if (it.quantity == null || Number.isNaN(it.quantity) || Number(it.quantity) <= 0) return true;
    if (!it.unit) return true;
    return false;
  }

  _resetUi() {
    this.previewWrapperTarget.classList.add('d-none');
    this.previewImageTarget.src = '';
    this.analyzeButtonTarget.disabled = true;
    this.resetButtonTarget.disabled = true;
    this.applyButtonTarget.disabled = true;
    this._hideResults();
    this._clearStatus();
  }

  _setLoading(isLoading) {
    this.analyzeButtonTarget.disabled = isLoading || !this.file;
    this.resetButtonTarget.disabled = isLoading ? true : !this.file;
    this.fileInputTarget.disabled = isLoading;

    if (isLoading) {
      this.analyzeButtonTarget.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Analyse…';
    } else {
      this.analyzeButtonTarget.innerHTML = '<i class="fa-solid fa-camera me-1"></i>Analyser le ticket';
    }
  }

  _unitOptions() {
    return [
      { value: 'g', label: 'g' },
      { value: 'kg', label: 'kg' },
      { value: 'ml', label: 'ml' },
      { value: 'l', label: 'L' },
      { value: 'piece', label: 'pièce' },
      { value: 'pack', label: 'pack' },
      { value: 'unknown', label: 'autre' },
    ];
  }

  _renderUnitOptions(current) {
    const opts = this._unitOptions();
    const cur = current ?? '';
    const first = `<option value="" ${cur === '' ? 'selected' : ''}>—</option>`;
    const rest = opts
      .map((o) => {
        const sel = o.value === cur ? 'selected' : '';
        return `<option value="${o.value}" ${sel}>${o.label}</option>`;
      })
      .join('');
    return first + rest;
  }

  _shouldAutoCheck(item) {
    const c = typeof item.confidence === 'number' ? item.confidence : 0;
    const needs = !!item.needs_confirmation; // undefined -> false
    return c >= 0.8 && needs === false && item.quantity != null && item.unit != null;
  }

  _isLowConfidence(item) {
    const c = typeof item.confidence === 'number' ? item.confidence : 0;
    return c < 0.8;
  }

  _refreshApplyButtonState() {
    // default selection for each row (if user didn't touch)
    this.items.forEach((it) => {
      if (typeof it._selected !== 'boolean') {
        it._selected = this._shouldAutoCheck(it);
      }
    });

    const selected = this.items.filter((it) => it._selected);
    const allSelectedAreValid = selected.length > 0 && selected.every((it) => !this._rowNeedsConfirmation(it));

    this.applyButtonTarget.disabled = !allSelectedAreValid;
  }

  _renderItems(items) {
    this.resultsTarget.classList.remove('d-none');
    this.resultsListTarget.innerHTML = '';

    if (!items.length) {
      this.applyButtonTarget.disabled = true;
      return;
    }

    const wrap = document.createElement('div');
    wrap.className = 'd-grid gap-2';

    items.forEach((it, idx) => {
      const checked = this._shouldAutoCheck(it);
      const lowConfidence = this._isLowConfidence(it);

      const row = document.createElement('div');
      row.className = 'p-2 rounded-3 border ' + (lowConfidence ? 'border-danger' : '');
      row.dataset.ticketRowIndex = String(idx);

      const badgeClass = lowConfidence ? 'text-bg-danger' : 'text-bg-success';
      const badgeLabel = lowConfidence ? 'À vérifier' : 'OK';
      const badgeIcon = lowConfidence
        ? '<i class="fa-solid fa-triangle-exclamation me-1"></i>'
        : '<i class="fa-solid fa-check me-1"></i>';

      row.innerHTML = `
        <div class="d-flex align-items-start gap-2">
          <div class="pt-1">
            <input class="form-check-input"
                   type="checkbox"
                   data-action="ticket-scan#onRowChange"
                   data-index="${idx}"
                   ${checked ? 'checked' : ''}>
          </div>

          <div class="flex-grow-1">
            <div class="d-flex align-items-start justify-content-between gap-2">
              <div class="flex-grow-1">
                <label class="form-label small text-muted mb-1">Ingrédient</label>
                <input type="text"
                       class="form-control form-control-sm"
                       value="${this._escapeHtml(it.name || it.name_raw || '')}"
                       data-action="input->ticket-scan#onRowChange"
                       data-field="name"
                       data-index="${idx}">
              </div>

              <div class="text-end">
                <span class="badge ${badgeClass}">
                  ${badgeIcon}${badgeLabel}
                </span>
                <div class="small text-muted mt-1">${this._confidenceLabel(it)}</div>
              </div>
            </div>

            <div class="row g-2 mt-1">
              <div class="col-6">
                <label class="form-label small text-muted mb-1">Quantité</label>
                <input type="number"
                       step="0.01"
                       min="0"
                       class="form-control form-control-sm"
                       value="${it.quantity ?? ''}"
                       placeholder="ex: 1"
                       data-action="input->ticket-scan#onRowChange"
                       data-field="quantity"
                       data-index="${idx}">
              </div>
              <div class="col-6">
                <label class="form-label small text-muted mb-1">Unité</label>
                <select class="form-select form-select-sm"
                        data-action="change->ticket-scan#onRowChange"
                        data-field="unit"
                        data-index="${idx}">
                  ${this._renderUnitOptions(it.unit)}
                </select>
              </div>
            </div>

            ${it.notes ? `<div class="small text-muted mt-2">${this._escapeHtml(it.notes)}</div>` : ''}
          </div>
        </div>
      `;

      wrap.appendChild(row);

      // set initial selection state
      it._selected = checked;
    });

    this.resultsListTarget.appendChild(wrap);
    this._refreshApplyButtonState();
  }

  // Rafraîchit uniquement la bordure/badge d’une ligne après édition (sans rerender tout)
  _refreshRowUi(idx) {
    const row = this.resultsListTarget.querySelector(`[data-ticket-row-index="${idx}"]`);
    if (!row) return;

    const it = this.items?.[idx];
    if (!it) return;

    const lowConfidence = this._isLowConfidence(it);

    row.classList.toggle('border-danger', lowConfidence);

    // Badge: on met à jour classe + label + icône
    const badge = row.querySelector('.badge');
    if (badge) {
      if (lowConfidence) {
        badge.classList.remove('text-bg-success');
        badge.classList.add('text-bg-danger');
        badge.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>À vérifier';
      } else {
        badge.classList.remove('text-bg-danger');
        badge.classList.add('text-bg-success');
        badge.innerHTML = '<i class="fa-solid fa-check me-1"></i>OK';
      }
    }
  }

  _confidenceLabel(it) {
    const c = typeof it.confidence === 'number' ? Math.round(it.confidence * 100) : null;
    return c !== null ? `${c}%` : '';
  }

  _escapeHtml(s) {
    return String(s)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  _hideResults() {
    this.resultsTarget.classList.add('d-none');
    this.resultsListTarget.innerHTML = '';
    this.applyButtonTarget.disabled = true;
  }

  _setStatus(kind, message) {
    // kind: success | info | warning | danger
    this.statusTarget.classList.remove('d-none');
    this.statusTarget.className = `alert alert-${kind} mb-0 mt-3`;
    this.statusTarget.textContent = message;
  }

  _clearStatus() {
    this.statusTarget.classList.add('d-none');
    this.statusTarget.textContent = '';
  }
}
