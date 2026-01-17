import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["messages", "input", "status", "micButton", "sendButton"];
  static values = {
    historyUrl: String,
    messageUrl: String,
    confirmUrl: String, // ✅ NEW
    transcribeUrl: { type: String, default: "/api/ai/transcribe" },

    locale: { type: String, default: "fr-FR" },

    // Firefox (WebAudio)
    prerollSeconds: { type: Number, default: 2 },
  };

  connect() {
    // Chat state
    this.isLoading = false;
    this.isSending = false;

    // Voice state
    this.mediaSupported = !!(navigator.mediaDevices && window.MediaRecorder);
    this.mediaRecorder = null;
    this.mediaStream = null;

    this.isRecording = false;
    this.isTranscribing = false;

    this.audioChunks = [];
    this.recordedBlob = null;

    this.isFirefox = /firefox/i.test(navigator.userAgent);

    // WebAudio (Firefox)
    this.audioCtx = null;
    this.sourceNode = null;
    this.processorNode = null;

    this.ringBuffer = [];
    this.ringBufferSamples = 0;
    this.sampleRate = 48000;
    this.isWebAudioRecording = false;

    this.setStatus("");
    this.loadHistory().finally(() => {
      if (this.hasInputTarget) this.inputTarget.focus();
    });

    // Micro unsupported
    if (!this.mediaSupported && !this.isFirefox) {
      if (this.hasMicButtonTarget) this.micButtonTarget.disabled = true;
    }
  }

  disconnect() {
    try {
      if (this.isRecording) this.stopRecord();
      this.stopStream();
      this.stopWebAudioGraph();
    } catch {
      // no-op
    }
  }

  // =========================================================
  // Chat: history + display
  // =========================================================
  async loadHistory() {
    if (!this.hasMessagesTarget) return;

    this.isLoading = true;
    this.setStatus("⏳ Chargement…");

    try {
      const res = await fetch(this.historyUrlValue, {
        headers: { Accept: "application/json" },
      });

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

    // ✅ Confirmation UI (assistant only)
    if (!isUser && payload && payload.type === "confirm" && message?.id) {
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

      // Si déjà confirmé (history reload), on désactive
      if (payload.confirmed === "yes" || payload.confirmed === "no") {
        yesBtn.disabled = true;
        noBtn.disabled = true;
      }

      const lock = () => {
        yesBtn.disabled = true;
        noBtn.disabled = true;
      };

      yesBtn.addEventListener("click", async () => {
        lock();
        await this.confirmAction(message.id, "yes");
      });

      noBtn.addEventListener("click", async () => {
        lock();
        await this.confirmAction(message.id, "no");
      });

      actions.appendChild(yesBtn);
      actions.appendChild(noBtn);
      bubbleContainer.appendChild(actions);
    }

    wrapper.appendChild(bubbleContainer);
    this.messagesTarget.appendChild(wrapper);
  }

  async confirmAction(messageId, decision) {
    if (!this.hasConfirmUrlValue || !this.confirmUrlValue) {
      this.addMessage({ role: "assistant", content: "⚠️ confirmUrl manquant côté front." });
      this.scrollToBottom();
      return;
    }

    try {
      this.setStatus("⏳ Confirmation…");

      const res = await fetch(this.confirmUrlValue, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ message_id: messageId, decision }),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        const msg = data?.error?.message || "Erreur serveur.";
        this.addMessage({ role: "assistant", content: `⚠️ ${msg}` });
        this.scrollToBottom();
        this.setStatus("");
        return;
      }

      const returned = Array.isArray(data.messages) ? data.messages : [];
      for (const m of returned) this.addMessage(m);

      this.scrollToBottom();
      this.setStatus("");
    } catch (e) {
      console.error("[assistant-chat] confirm failed", e);
      this.addMessage({ role: "assistant", content: "⚠️ Erreur réseau." });
      this.scrollToBottom();
      this.setStatus("");
    }
  }

  scrollToBottom() {
    if (!this.hasMessagesTarget) return;
    requestAnimationFrame(() => {
      this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    });
  }

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
    this.setStatus("⏳ Envoi…");
    this.setSendingState(true);

    try {
      const res = await fetch(this.messageUrlValue, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ text, source: "typed" }),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        const msg = data?.error?.message || "Erreur serveur.";
        this.addMessage({ role: "assistant", content: `⚠️ ${msg}` });
        this.scrollToBottom();
        this.setStatus("");
        return;
      }

      const returned = Array.isArray(data.messages) ? data.messages : [];
      for (const m of returned) {
        if ((m?.role || "").toLowerCase() === "user") continue;
        this.addMessage(m);
      }

      this.scrollToBottom();
      this.setStatus("");
    } catch (e) {
      console.error("[assistant-chat] send failed", e);
      this.addMessage({ role: "assistant", content: "⚠️ Erreur réseau." });
      this.scrollToBottom();
      this.setStatus("");
    } finally {
      this.isSending = false;
      this.setSendingState(false);
      if (this.hasInputTarget) this.inputTarget.focus();
    }
  }

  // =========================================================
  // Voice: mic toggle start/stop + auto transcribe into input
  // =========================================================
  async toggleMic() {
    if (this.isTranscribing) return;

    if (!this.mediaSupported && !this.isFirefox) {
      this.setStatus("⚠️ Enregistrement audio non supporté ici.");
      return;
    }

    if (this.isRecording) {
      this.stopRecord();
    } else {
      await this.startRecord();
    }
  }

  async ensureStream() {
    if (this.mediaStream) return this.mediaStream;
    this.setStatus("🎙️ Activation du micro…");
    this.mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    return this.mediaStream;
  }

  stopStream() {
    if (!this.mediaStream) return;
    try {
      this.mediaStream.getTracks().forEach((t) => t.stop());
    } catch {
      // no-op
    }
    this.mediaStream = null;
  }

  async startRecord() {
    this.recordedBlob = null;

    this.isRecording = true;
    this._setMicButtonState(true);
    this.setStatus("⏳ Préparation…");

    if (this.isFirefox) {
      await this.startFirefoxWebAudio();
      return;
    }

    if (!this.mediaSupported) {
      this.setStatus("⚠️ MediaRecorder non supporté ici.");
      this.isRecording = false;
      this._setMicButtonState(false);
      return;
    }

    try {
      this.audioChunks = [];
      await this.ensureStream();

      const mimeType = this.pickAudioMimeType();
      this.mediaRecorder = new MediaRecorder(
        this.mediaStream,
        mimeType ? { mimeType } : undefined
      );

      this.mediaRecorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) this.audioChunks.push(e.data);
      };

      this.mediaRecorder.onstop = async () => {
        const type = this.mediaRecorder?.mimeType || "audio/webm";
        this.recordedBlob = new Blob(this.audioChunks, { type });

        if (this.recordedBlob.size === 0) {
          this.setStatus("⚠️ Audio vide. Réessaie.");
          return;
        }

        await this.autoTranscribeIntoInput();
      };

      this.mediaRecorder.start(250);
      this.beep();
      this.setStatus("🔴 Enregistrement… (re-clique pour arrêter)");
    } catch (e) {
      console.error("[assistant-chat] startRecord error", e);
      this.setStatus("⚠️ Micro refusé ou indisponible.");
      this.isRecording = false;
      this._setMicButtonState(false);
      this.stopStream();
    }
  }

  stopRecord() {
    if (!this.isRecording) return;

    this.isRecording = false;
    this._setMicButtonState(false);
    this.setStatus("⏳ Arrêt…");

    if (this.isFirefox) {
      this.stopFirefoxWebAudio();
      return;
    }

    if (!this.mediaRecorder) return;

    try {
      if (typeof this.mediaRecorder.requestData === "function") {
        this.mediaRecorder.requestData();
      }
    } catch {
      // ignore
    }

    try {
      setTimeout(() => this.mediaRecorder.stop(), 50);
    } catch (e) {
      console.error("[assistant-chat] stop error", e);
      this.setStatus("⚠️ Impossible d’arrêter l’enregistrement.");
    }
  }

  // Firefox WebAudio
  async startFirefoxWebAudio() {
    try {
      await this.ensureStream();

      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        this.setStatus("⚠️ WebAudio non supporté.");
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

      this.processorNode = this.audioCtx.createScriptProcessor(
        bufferSize,
        numChannels,
        numChannels
      );

      this.ringBuffer = [];
      this.ringBufferSamples = 0;

      this.recordBuffers = [];
      this.recordSamples = 0;
      this.isWebAudioRecording = true;

      this.processorNode.onaudioprocess = (event) => {
        const input = event.inputBuffer.getChannelData(0);
        const chunk = new Float32Array(input.length);
        chunk.set(input);

        this.pushToRing(chunk);

        if (this.isWebAudioRecording) {
          this.recordBuffers.push(chunk);
          this.recordSamples += chunk.length;
        }
      };

      this.sourceNode.connect(this.processorNode);
      this.processorNode.connect(this.audioCtx.destination);

      this.beep();
      this.setStatus("🔴 Enregistrement (Firefox)… (re-clique pour arrêter)");
    } catch (e) {
      console.error("[assistant-chat] Firefox WebAudio start error", e);
      this.setStatus("⚠️ Erreur démarrage audio (Firefox).");
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

      this.autoTranscribeIntoInput().catch((e) => {
        console.error("[assistant-chat] auto transcribe (Firefox) failed", e);
      });
    } catch (e) {
      console.error("[assistant-chat] Firefox stop error", e);
      this.setStatus("⚠️ Erreur arrêt audio (Firefox).");
      this.stopWebAudioGraph();
    }
  }

  stopWebAudioGraph() {
    try {
      if (this.processorNode) {
        this.processorNode.disconnect();
        this.processorNode.onaudioprocess = null;
      }
      if (this.sourceNode) {
        this.sourceNode.disconnect();
      }
    } catch {
      // ignore
    }
    this.processorNode = null;
    this.sourceNode = null;
  }

  pushToRing(chunk) {
    const maxSamples = Math.floor(this.sampleRate * this.prerollSecondsValue);
    this.ringBuffer.push(chunk);
    this.ringBufferSamples += chunk.length;

    while (this.ringBufferSamples > maxSamples && this.ringBuffer.length > 0) {
      const removed = this.ringBuffer.shift();
      this.ringBufferSamples -= removed.length;
    }
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
    for (let i = 0; i < str.length; i++) {
      view.setUint8(offset + i, str.charCodeAt(i));
    }
  }

  async autoTranscribeIntoInput() {
    if (this.isTranscribing) return;

    if (!this.recordedBlob || this.recordedBlob.size === 0) {
      this.setStatus("⚠️ Audio vide. Réessaie.");
      return;
    }

    this.isTranscribing = true;
    this.setStatus("⏳ Transcription…");
    this.setSendingState(true);

    try {
      const text = await this.transcribeAudio();
      if (!text) return;

      if (this.hasInputTarget) {
        this.inputTarget.value = text;
        this.inputTarget.focus();
      }

      this.setStatus("✅ Transcription prête.");
    } finally {
      this.isTranscribing = false;
      this.setSendingState(false);
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
    } catch (e) {
      console.error("[assistant-chat] fetch transcribe failed", e);
      this.setStatus("❌ Erreur réseau transcription.");
      return null;
    }

    const data = await res.json().catch(() => null);
    if (!data) {
      this.setStatus("❌ Réponse transcription invalide.");
      return null;
    }

    if (!res.ok) {
      const msg = data?.error?.message || "Erreur transcription serveur.";
      this.setStatus(`❌ ${msg}`);
      return null;
    }

    const text = (data.text || "").trim();
    if (!text) {
      this.setStatus("⚠️ Transcription vide.");
      return null;
    }

    return text;
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

  // =========================================================
  // UI helpers
  // =========================================================
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
      setTimeout(() => {
        o.stop();
        ctx.close();
      }, 120);
    } catch {
      // ignore
    }
  }
}
