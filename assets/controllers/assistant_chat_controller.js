import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["messages", "input", "status", "micButton", "sendButton"];
  static values = {
    historyUrl: String,
    messageUrl: String,
    confirmUrl: String,
    clarifyUrl: String,

    transcribeUrl: { type: String, default: "/api/ai/transcribe" },
    locale: { type: String, default: "fr-FR" },

    prerollSeconds: { type: Number, default: 2 },
  };

  connect() {
    this.isLoading = false;
    this.isSending = false;

    // Voice
    this.mediaSupported = !!(navigator.mediaDevices && window.MediaRecorder);
    this.mediaRecorder = null;
    this.mediaStream = null;
    this.isRecording = false;
    this.isTranscribing = false;
    this.audioChunks = [];
    this.recordedBlob = null;

    this.isFirefox = /firefox/i.test(navigator.userAgent);
    this.audioCtx = null;
    this.sourceNode = null;
    this.processorNode = null;
    this.sampleRate = 48000;
    this.isWebAudioRecording = false;

    this.setStatus("");
    this.loadHistory().finally(() => {
      if (this.hasInputTarget) this.inputTarget.focus();
    });

    if (!this.mediaSupported && !this.isFirefox) {
      if (this.hasMicButtonTarget) this.micButtonTarget.disabled = true;
    }
  }

  disconnect() {
    try {
      if (this.isRecording) this.stopRecord();
      this.stopStream();
      this.stopWebAudioGraph();
    } catch {}
  }

  // =========================================================
  // History
  // =========================================================
  async loadHistory() {
    if (!this.hasMessagesTarget) return;

    this.isLoading = true;
    this.setStatus("⏳ Chargement…");

    try {
      const res = await fetch(this.historyUrlValue, { headers: { Accept: "application/json" } });
      if (!res.ok) {
        this.setStatus("⚠️ Impossible de charger la conversation.");
        return;
      }

      const data = await res.json().catch(() => ({}));
      const messages = Array.isArray(data.messages) ? data.messages : [];

      this.messagesTarget.innerHTML = "";

      if (messages.length === 0) {
        this.addMessage({ role: "assistant", content: "Que puis-je pour toi ?" });
      } else {
        for (const m of messages) this.addMessage(m);
      }

      this.scrollToBottom();
      this.setStatus("");
    } catch (e) {
      console.error("[assistant-chat] loadHistory failed", e);
      this.setStatus("⚠️ Erreur réseau.");
    } finally {
      this.isLoading = false;
    }
  }

  // =========================================================
  // Rendering
  // =========================================================
  addMessage(message) {
    const role = (message?.role || "assistant").toLowerCase();
    const content = (message?.content || "").toString();
    const payload = message?.payload || null;
    const isUser = role === "user";

    const wrapper = document.createElement("div");
    wrapper.className = `d-flex mb-2 ${isUser ? "justify-content-end" : "justify-content-start"}`;

    const bubbleContainer = document.createElement("div");
    bubbleContainer.style.maxWidth = "80%";

    const bubble = document.createElement("div");
    bubble.className = isUser
      ? "px-3 py-2 rounded-4 text-white"
      : "px-3 py-2 rounded-4 border bg-white";
    if (isUser) bubble.style.background = "#0d6efd";
    bubble.textContent = content;

    bubbleContainer.appendChild(bubble);

    // ✅ CLARIFY
    if (!isUser && payload?.type === "clarify" && message?.id) {
      const alreadyClarified = payload.clarified === true;
      if (!alreadyClarified) {
        const clarifyBlock = this.buildClarifyBlock(message.id, payload);
        if (clarifyBlock) bubbleContainer.appendChild(clarifyBlock);
      }
    }

    // ✅ CONFIRM
    if (!isUser && payload?.type === "confirm" && message?.id) {
      const alreadyConfirmed = payload.confirmed === "yes" || payload.confirmed === "no";
      if (!alreadyConfirmed) {
        // details + edit
        const detailBlock = this.buildDetailsBlock(payload, message.id);
        if (detailBlock) bubbleContainer.appendChild(detailBlock);

        const actions = document.createElement("div");
        actions.className = "d-flex gap-2 mt-2";

        const yesBtn = document.createElement("button");
        yesBtn.type = "button";
        yesBtn.className = "btn btn-sm btn-success";
        yesBtn.textContent = "Oui";

        const noBtn = document.createElement("button");
        noBtn.type = "button";
        noBtn.className = "btn btn-sm btn-outline-secondary";
        noBtn.textContent = "Non";

        const removeConfirmUi = () => {
          try { if (actions.parentNode) actions.parentNode.removeChild(actions); } catch {}
          try { if (detailBlock && detailBlock.parentNode) detailBlock.parentNode.removeChild(detailBlock); } catch {}
        };

        yesBtn.addEventListener("click", async () => {
          // récupérer un payload édité si présent
          const edited = detailBlock?.dataset?.editedPayload
            ? JSON.parse(detailBlock.dataset.editedPayload)
            : null;

          removeConfirmUi();
          await this.confirmAction(message.id, "yes", edited);
        });

        noBtn.addEventListener("click", async () => {
          removeConfirmUi();
          await this.confirmAction(message.id, "no", null);
        });

        actions.appendChild(yesBtn);
        actions.appendChild(noBtn);
        bubbleContainer.appendChild(actions);
      }
    }

    wrapper.appendChild(bubbleContainer);
    this.messagesTarget.appendChild(wrapper);
  }

  // =========================================================
  // Étape 3: Clarify UI
  // =========================================================
  buildClarifyBlock(messageId, payload) {
    if (!this.hasClarifyUrlValue || !this.clarifyUrlValue) return null;

    const questions = Array.isArray(payload.questions) ? payload.questions : [];
    if (questions.length === 0) return null;

    const container = document.createElement("div");
    container.className = "mt-2";

    const form = document.createElement("div");
    form.className = "border rounded-3 bg-light p-2";

    const inputs = new Map();

    for (const q of questions) {
      if (!q?.path || !q?.label) continue;

      const row = document.createElement("div");
      row.className = "mb-2";

      const label = document.createElement("div");
      label.className = "small mb-1";
      label.textContent = q.label;
      row.appendChild(label);

      let input;

      if (q.kind === "select") {
        input = document.createElement("select");
        input.className = "form-select form-select-sm";

        const opt0 = document.createElement("option");
        opt0.value = "";
        opt0.textContent = "—";
        input.appendChild(opt0);

        const options = Array.isArray(q.options) ? q.options : [];
        for (const opt of options) {
          const o = document.createElement("option");
          o.value = (opt?.value ?? "").toString();
          o.textContent = (opt?.label ?? opt?.value ?? "").toString();
          input.appendChild(o);
        }
      } else {
        input = document.createElement("input");
        input.className = "form-control form-control-sm";
        input.type = q.kind === "number" ? "number" : "text";
        if (q.placeholder) input.placeholder = q.placeholder;
        if (q.kind === "number") {
          input.step = "any";
          input.inputMode = "decimal";
        }
      }

      inputs.set(q.path, input);
      row.appendChild(input);
      form.appendChild(row);
    }

    const actions = document.createElement("div");
    actions.className = "d-flex justify-content-end";

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btn btn-sm btn-primary";
    btn.textContent = "Continuer";

    const removeClarifyUi = () => {
      try { if (container.parentNode) container.parentNode.removeChild(container); } catch {}
    };

    btn.addEventListener("click", async () => {
      const answers = {};

      // exige réponse à tous les champs affichés
      for (const q of questions) {
        if (!q?.path) continue;
        const el = inputs.get(q.path);
        const v = (el?.value ?? "").toString().trim();
        if (v === "") {
          el?.classList.add("is-invalid");
          return;
        }
        el?.classList.remove("is-invalid");

        if (el.tagName === "INPUT" && el.type === "number") {
          const n = Number(v);
          answers[q.path] = Number.isFinite(n) ? n : v;
        } else {
          answers[q.path] = v;
        }
      }

      removeClarifyUi();
      await this.submitClarify(messageId, answers);
    });

    actions.appendChild(btn);
    form.appendChild(actions);

    container.appendChild(form);
    return container;
  }

  async submitClarify(messageId, answers) {
    try {
      const res = await fetch(this.clarifyUrlValue, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ message_id: messageId, answers }),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        const msg = data?.error?.message || "Erreur serveur.";
        this.addMessage({ role: "assistant", content: `⚠️ ${msg}` });
        this.scrollToBottom();
        return;
      }

      const returned = Array.isArray(data.messages) ? data.messages : [];
      for (const m of returned) this.addMessage(m);
      this.scrollToBottom();
    } catch (e) {
      console.error("[assistant-chat] clarify failed", e);
      this.addMessage({ role: "assistant", content: "⚠️ Erreur réseau." });
      this.scrollToBottom();
    }
  }

  // =========================================================
  // Étape 4: Details + édition inline (confirm)
  // =========================================================
  buildDetailsBlock(payload, messageId) {
    const action = payload.action;
    const ap = payload.action_payload;

    const container = document.createElement("div");
    container.className = "mt-2";
    container.dataset.messageId = String(messageId);

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "btn btn-sm btn-link p-0";
    toggle.textContent = "Détails";
    toggle.style.textDecoration = "none";

    const body = document.createElement("div");
    body.className = "border rounded-3 bg-light mt-1 p-2";
    body.style.display = "none";

    // Draft éditable (on copie profondément le payload)
    let draft = ap ? JSON.parse(JSON.stringify(ap)) : null;

    // Helpers de rendu
    const renderReadOnlyList = () => {
      body.innerHTML = "";

      const lines = this.detailsLinesForAction(action, draft);
      if (lines.length === 0) return;

      const ul = document.createElement("ul");
      ul.className = "mb-2 ps-3";

      for (const l of lines.slice(0, 12)) {
        const li = document.createElement("li");
        li.className = "small";
        li.textContent = l;
        ul.appendChild(li);
      }
      if (lines.length > 12) {
        const li = document.createElement("li");
        li.className = "small text-muted";
        li.textContent = `+${lines.length - 12} autres…`;
        ul.appendChild(li);
      }
      body.appendChild(ul);

      // ✅ bouton Modifier uniquement pour add_stock pour l’instant
      if (action === "add_stock" && draft?.items && Array.isArray(draft.items)) {
        const row = document.createElement("div");
        row.className = "d-flex justify-content-end";

        const editBtn = document.createElement("button");
        editBtn.type = "button";
        editBtn.className = "btn btn-sm btn-outline-primary";
        editBtn.textContent = "Modifier";

        editBtn.addEventListener("click", () => renderEditor());

        row.appendChild(editBtn);
        body.appendChild(row);
      }
    };

    const renderEditor = () => {
      body.innerHTML = "";

      const items = Array.isArray(draft?.items) ? draft.items : [];
      if (items.length === 0) {
        renderReadOnlyList();
        return;
      }

      // Editor list
      for (let i = 0; i < items.length; i++) {
        const it = items[i] || {};
        const card = document.createElement("div");
        card.className = "border rounded-3 bg-white p-2 mb-2";

        // name
        const nameInput = document.createElement("input");
        nameInput.className = "form-control form-control-sm mb-2";
        nameInput.type = "text";
        nameInput.placeholder = "Nom";
        nameInput.value = (it.name || it.name_raw || "").toString();

        // quantity
        const qtyInput = document.createElement("input");
        qtyInput.className = "form-control form-control-sm mb-2";
        qtyInput.type = "number";
        qtyInput.step = "any";
        qtyInput.inputMode = "decimal";
        qtyInput.placeholder = "Quantité";
        qtyInput.value = it.quantity ?? it.quantity_raw ?? "";

        // unit
        const unitSelect = document.createElement("select");
        unitSelect.className = "form-select form-select-sm";
        const unitOptions = [
          { value: "", label: "—" },
          { value: "piece", label: "pièce(s)" },
          { value: "g", label: "g" },
          { value: "kg", label: "kg" },
          { value: "ml", label: "mL" },
          { value: "l", label: "L" },
        ];
        for (const opt of unitOptions) {
          const o = document.createElement("option");
          o.value = opt.value;
          o.textContent = opt.label;
          unitSelect.appendChild(o);
        }
        unitSelect.value = (it.unit || it.unit_raw || "").toString();

        // remove item (optionnel)
        const removeRow = document.createElement("div");
        removeRow.className = "d-flex justify-content-end mt-2";

        const removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.className = "btn btn-sm btn-outline-danger";
        removeBtn.textContent = "Supprimer";

        removeBtn.addEventListener("click", () => {
          draft.items.splice(i, 1);
          renderEditor();
        });

        removeRow.appendChild(removeBtn);

        card.appendChild(nameInput);
        card.appendChild(qtyInput);
        card.appendChild(unitSelect);
        card.appendChild(removeRow);

        // bind changes
        const applyItemChanges = () => {
          const name = nameInput.value.trim();
          const qtyStr = qtyInput.value.toString().trim();
          const unit = unitSelect.value.trim();

          if (name) {
            it.name = name;
            it.name_raw = name;
          }

          if (qtyStr !== "" && !Number.isNaN(Number(qtyStr))) {
            const n = Number(qtyStr);
            it.quantity = n;
            it.quantity_raw = qtyStr;
          } else {
            it.quantity = null;
            it.quantity_raw = qtyStr || null;
          }

          it.unit = unit || null;
          it.unit_raw = null;
        };

        nameInput.addEventListener("input", applyItemChanges);
        qtyInput.addEventListener("input", applyItemChanges);
        unitSelect.addEventListener("change", applyItemChanges);

        // ensure initial sync
        applyItemChanges();

        body.appendChild(card);
      }

      // actions save/cancel
      const footer = document.createElement("div");
      footer.className = "d-flex justify-content-end gap-2";

      const cancelBtn = document.createElement("button");
      cancelBtn.type = "button";
      cancelBtn.className = "btn btn-sm btn-outline-secondary";
      cancelBtn.textContent = "Annuler";

      const saveBtn = document.createElement("button");
      saveBtn.type = "button";
      saveBtn.className = "btn btn-sm btn-primary";
      saveBtn.textContent = "OK";

      cancelBtn.addEventListener("click", () => {
        // reset draft depuis payload initial
        draft = ap ? JSON.parse(JSON.stringify(ap)) : null;
        container.dataset.editedPayload = "";
        renderReadOnlyList();
      });

      saveBtn.addEventListener("click", () => {
        // simple validation: enlever items vides
        if (draft?.items && Array.isArray(draft.items)) {
          draft.items = draft.items
            .map((it) => it || {})
            .filter((it) => (it.name || it.name_raw || "").toString().trim() !== "");
        }

        // stocker le payload édité sur le container (lu au clic Oui)
        container.dataset.editedPayload = JSON.stringify(draft);
        renderReadOnlyList();
      });

      footer.appendChild(cancelBtn);
      footer.appendChild(saveBtn);
      body.appendChild(footer);
    };

    toggle.addEventListener("click", () => {
      const show = body.style.display === "none";
      body.style.display = show ? "block" : "none";
      if (show) renderReadOnlyList();
    });

    container.appendChild(toggle);
    container.appendChild(body);

    return container;
  }

  detailsLinesForAction(action, draft) {
    let lines = [];
    if (action === "add_stock") {
      const items = Array.isArray(draft?.items) ? draft.items : [];
      lines = items.map((it) => this.formatStockLine(it)).filter(Boolean);
    } else if (action === "add_recipe") {
      const name = (draft?.name || draft?.recipe_name || "").toString().trim();
      if (name) lines.push(`Recette : ${name}`);
      const ingredients = Array.isArray(draft?.ingredients) ? draft.ingredients : [];
      for (const ing of ingredients.slice(0, 10)) {
        const line = this.formatRecipeIngredientLine(ing);
        if (line) lines.push(line);
      }
      if (ingredients.length > 10) lines.push(`+${ingredients.length - 10} autres ingrédients…`);
    }
    return lines;
  }

  unitLabel(unit, qty) {
    const u = (unit || "").toString().trim().toLowerCase();
    const q = Number(qty);
    const isOne = Number.isFinite(q) && q === 1;

    switch (u) {
      case "piece":
        return isOne ? "pièce" : "pièces";
      case "g":
        return "g";
      case "kg":
        return "kg";
      case "ml":
        return "mL";
      case "l":
        return "L";
      default:
        return u || "";
    }
  }

  formatStockLine(it) {
    if (!it || typeof it !== "object") return null;

    const name = (it.name || it.name_raw || "").toString().trim();
    if (!name) return null;

    const qty =
      it.quantity !== undefined && it.quantity !== null && `${it.quantity}` !== ""
        ? Number(it.quantity)
        : null;

    const qtyRaw = (it.quantity_raw || "").toString().trim();
    const unit = (it.unit || it.unit_raw || "").toString().trim();

    let line = name;

    if (qty !== null && Number.isFinite(qty)) {
      const uLbl = this.unitLabel(unit, qty);
      line = uLbl ? `${name} — ${qty} ${uLbl}` : `${name} — ${qty}`;
    } else if (qtyRaw) {
      line = unit ? `${name} — ${qtyRaw} ${unit}` : `${name} — ${qtyRaw}`;
    }

    const notes = (it.notes || "").toString().trim();
    if (notes) line += ` (${notes})`;

    const conf = Number(it.confidence);
    if (Number.isFinite(conf) && conf < 0.8) line += ` (confiance ${conf.toFixed(2)})`;

    return line;
  }

  formatRecipeIngredientLine(ing) {
    if (!ing || typeof ing !== "object") return null;
    const name = (ing.name || ing.ingredient || ing.name_raw || "").toString().trim();
    if (!name) return null;

    const qty =
      ing.quantity !== undefined && ing.quantity !== null && `${ing.quantity}` !== ""
        ? Number(ing.quantity)
        : null;

    const qtyRaw = (ing.quantity_raw || "").toString().trim();
    const unit = (ing.unit || ing.unit_raw || "").toString().trim();

    if (qty !== null && Number.isFinite(qty)) {
      const uLbl = this.unitLabel(unit, qty);
      return uLbl ? `${name} — ${qty} ${uLbl}` : `${name} — ${qty}`;
    }
    if (qtyRaw) return unit ? `${name} — ${qtyRaw} ${unit}` : `${name} — ${qtyRaw}`;
    return `${name}`;
  }

  // =========================================================
  // Confirm (avec override payload)
  // =========================================================
  async confirmAction(messageId, decision, actionPayloadOverride) {
    try {
      const body = { message_id: messageId, decision };
      if (decision === "yes" && actionPayloadOverride && typeof actionPayloadOverride === "object") {
        body.action_payload = actionPayloadOverride;
      }

      const res = await fetch(this.confirmUrlValue, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(body),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        const msg = data?.error?.message || "Erreur serveur.";
        this.addMessage({ role: "assistant", content: `⚠️ ${msg}` });
        this.scrollToBottom();
        return;
      }

      const returned = Array.isArray(data.messages) ? data.messages : [];
      for (const m of returned) this.addMessage(m);
      this.scrollToBottom();
    } catch (e) {
      console.error("[assistant-chat] confirm failed", e);
      this.addMessage({ role: "assistant", content: "⚠️ Erreur réseau." });
      this.scrollToBottom();
    }
  }

  // =========================================================
  // Send
  // =========================================================
  async send(event) {
    if (event?.isComposing) return;
    if (this.isSending || this.isLoading || this.isTranscribing) return;
    if (!this.hasInputTarget) return;

    const text = this.inputTarget.value.trim();
    if (!text) return;

    this.addMessage({ role: "user", content: text });
    this.scrollToBottom();

    this.inputTarget.value = "";
    this.isSending = true;
    this.setSendingState(true);

    try {
      const res = await fetch(this.messageUrlValue, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ text, source: "typed" }),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        const msg = data?.error?.message || "Erreur serveur.";
        this.addMessage({ role: "assistant", content: `⚠️ ${msg}` });
        this.scrollToBottom();
        return;
      }

      const returned = Array.isArray(data.messages) ? data.messages : [];
      for (const m of returned) {
        if ((m?.role || "").toLowerCase() === "user") continue;
        this.addMessage(m);
      }

      this.scrollToBottom();
    } catch (e) {
      console.error("[assistant-chat] send failed", e);
      this.addMessage({ role: "assistant", content: "⚠️ Erreur réseau." });
      this.scrollToBottom();
    } finally {
      this.isSending = false;
      this.setSendingState(false);
      if (this.hasInputTarget) this.inputTarget.focus();
    }
  }

  scrollToBottom() {
    if (!this.hasMessagesTarget) return;
    requestAnimationFrame(() => {
      this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    });
  }

  // =========================================================
  // Voice (inchangé)
  // =========================================================
  async toggleMic() {
    if (this.isTranscribing) return;
    if (!this.mediaSupported && !this.isFirefox) return;

    if (this.isRecording) this.stopRecord();
    else await this.startRecord();
  }

  async ensureStream() {
    if (this.mediaStream) return this.mediaStream;
    this.mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    return this.mediaStream;
  }

  stopStream() {
    if (!this.mediaStream) return;
    try { this.mediaStream.getTracks().forEach((t) => t.stop()); } catch {}
    this.mediaStream = null;
  }

  async startRecord() {
    this.recordedBlob = null;
    this.isRecording = true;
    this._setMicButtonState(true);

    if (this.isFirefox) {
      await this.startFirefoxWebAudio();
      return;
    }

    if (!this.mediaSupported) {
      this.isRecording = false;
      this._setMicButtonState(false);
      return;
    }

    try {
      this.audioChunks = [];
      await this.ensureStream();

      const mimeType = this.pickAudioMimeType();
      this.mediaRecorder = new MediaRecorder(this.mediaStream, mimeType ? { mimeType } : undefined);

      this.mediaRecorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) this.audioChunks.push(e.data);
      };

      this.mediaRecorder.onstop = async () => {
        const type = this.mediaRecorder?.mimeType || "audio/webm";
        this.recordedBlob = new Blob(this.audioChunks, { type });
        if (this.recordedBlob.size === 0) return;
        await this.autoTranscribeIntoInput();
      };

      this.mediaRecorder.start(250);
      this.beep();
    } catch (e) {
      console.error("[assistant-chat] startRecord error", e);
      this.isRecording = false;
      this._setMicButtonState(false);
      this.stopStream();
    }
  }

  stopRecord() {
    if (!this.isRecording) return;

    this.isRecording = false;
    this._setMicButtonState(false);

    if (this.isFirefox) {
      this.stopFirefoxWebAudio();
      return;
    }

    if (!this.mediaRecorder) return;

    try { if (typeof this.mediaRecorder.requestData === "function") this.mediaRecorder.requestData(); } catch {}
    try { setTimeout(() => this.mediaRecorder.stop(), 50); } catch {}
  }

  async startFirefoxWebAudio() {
    try {
      await this.ensureStream();

      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        this.isRecording = false;
        this._setMicButtonState(false);
        return;
      }

      if (!this.audioCtx) this.audioCtx = new AudioCtx();
      if (this.audioCtx.state === "suspended") await this.audioCtx.resume();

      this.sampleRate = this.audioCtx.sampleRate;
      this.sourceNode = this.audioCtx.createMediaStreamSource(this.mediaStream);

      const bufferSize = 4096;
      const numChannels = 1;

      this.processorNode = this.audioCtx.createScriptProcessor(bufferSize, numChannels, numChannels);

      this.recordBuffers = [];
      this.recordSamples = 0;
      this.isWebAudioRecording = true;

      this.processorNode.onaudioprocess = (event) => {
        const input = event.inputBuffer.getChannelData(0);
        const chunk = new Float32Array(input.length);
        chunk.set(input);

        if (this.isWebAudioRecording) {
          this.recordBuffers.push(chunk);
          this.recordSamples += chunk.length;
        }
      };

      this.sourceNode.connect(this.processorNode);
      this.processorNode.connect(this.audioCtx.destination);

      this.beep();
    } catch (e) {
      console.error("[assistant-chat] Firefox WebAudio start error", e);
      this.isWebAudioRecording = false;
      this.stopWebAudioGraph();
      this.isRecording = false;
      this._setMicButtonState(false);
    }
  }

  stopFirefoxWebAudio() {
    try {
      this.isWebAudioRecording = false;

      const recorded = this.concatFloat32(this.recordBuffers, this.recordSamples);
      const wavArrayBuffer = this.encodeWav16(recorded, this.sampleRate);
      this.recordedBlob = new Blob([wavArrayBuffer], { type: "audio/wav" });

      this.stopWebAudioGraph();
      this.autoTranscribeIntoInput().catch(() => {});
    } catch (e) {
      console.error("[assistant-chat] Firefox stop error", e);
      this.stopWebAudioGraph();
    }
  }

  stopWebAudioGraph() {
    try {
      if (this.processorNode) {
        this.processorNode.disconnect();
        this.processorNode.onaudioprocess = null;
      }
      if (this.sourceNode) this.sourceNode.disconnect();
    } catch {}
    this.processorNode = null;
    this.sourceNode = null;
  }

  concatFloat32(chunks, totalSamples) {
    const out = new Float32Array(totalSamples);
    let offset = 0;
    for (const c of chunks) {
      out.set(c, offset);
      offset += c.length;
    }
    return out;
  }

  encodeWav16(float32Samples, sampleRate) {
    const numChannels = 1;
    const bytesPerSample = 2;
    const blockAlign = numChannels * bytesPerSample;
    const byteRate = sampleRate * blockAlign;
    const dataSize = float32Samples.length * bytesPerSample;

    const buffer = new ArrayBuffer(44 + dataSize);
    const view = new DataView(buffer);

    this.writeString(view, 0, "RIFF");
    view.setUint32(4, 36 + dataSize, true);
    this.writeString(view, 8, "WAVE");

    this.writeString(view, 12, "fmt ");
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, numChannels, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, byteRate, true);
    view.setUint16(32, blockAlign, true);
    view.setUint16(34, 16, true);

    this.writeString(view, 36, "data");
    view.setUint32(40, dataSize, true);

    let offset = 44;
    for (let i = 0; i < float32Samples.length; i++) {
      let s = float32Samples[i];
      s = Math.max(-1, Math.min(1, s));
      const int16 = s < 0 ? s * 0x8000 : s * 0x7fff;
      view.setInt16(offset, int16, true);
      offset += 2;
    }

    return buffer;
  }

  writeString(view, offset, str) {
    for (let i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i));
  }

  async autoTranscribeIntoInput() {
    if (this.isTranscribing) return;
    if (!this.recordedBlob || this.recordedBlob.size === 0) return;

    this.isTranscribing = true;
    try {
      const text = await this.transcribeAudio();
      if (!text) return;
      if (this.hasInputTarget) {
        this.inputTarget.value = text;
        this.inputTarget.focus();
      }
    } finally {
      this.isTranscribing = false;
    }
  }

  async transcribeAudio() {
    const fd = new FormData();

    const mime = this.recordedBlob.type || "audio/webm";
    const ext = mime.includes("wav") ? "wav" : mime.includes("ogg") ? "ogg" : "webm";
    const file = new File([this.recordedBlob], `voice.${ext}`, { type: mime });

    fd.append("audio", file);
    fd.append("locale", this.localeValue);

    let res;
    try {
      res = await fetch(this.transcribeUrlValue, { method: "POST", body: fd });
    } catch {
      return null;
    }

    const data = await res.json().catch(() => null);
    if (!data || !res.ok) return null;

    const text = (data.text || "").trim();
    return text || null;
  }

  pickAudioMimeType() {
    const candidates = [
      "audio/webm;codecs=opus",
      "audio/webm",
      "audio/ogg;codecs=opus",
      "audio/ogg",
      "video/webm;codecs=opus",
      "video/webm",
    ];
    for (const t of candidates) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported(t)) return t;
    }
    return null;
  }

  setStatus(msg) {
    if (this.hasStatusTarget) this.statusTarget.textContent = msg;
  }

  setSendingState(isBusy) {
    if (this.hasSendButtonTarget) this.sendButtonTarget.disabled = isBusy;
    if (this.hasMicButtonTarget) this.micButtonTarget.disabled = isBusy;
    if (this.hasInputTarget) this.inputTarget.disabled = isBusy;
  }

  _setMicButtonState(isRecording) {
    if (!this.hasMicButtonTarget) return;
    this.micButtonTarget.textContent = isRecording ? "⏹️" : "🎙️";
    this.micButtonTarget.setAttribute("aria-pressed", isRecording ? "true" : "false");
  }

  beep() {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.connect(g);
      g.connect(ctx.destination);
      o.frequency.value = 880;
      g.gain.value = 0.05;
      o.start();
      setTimeout(() => { o.stop(); ctx.close(); }, 120);
    } catch {}
  }
}
