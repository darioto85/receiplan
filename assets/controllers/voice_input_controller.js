import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["textarea", "status", "recordButton", "validateButton"];
  static values = {
    locale: { type: String, default: "fr-FR" },
    transcribeUrl: { type: String, default: "/api/ai/transcribe" },
    parseUrl: { type: String, default: "/api/ai/parse" },
    userId: Number,
    prerollSeconds: { type: Number, default: 2 }, // Firefox: pré-roll PCM (secondes)
  };

  connect() {
    console.log("[voice-input] connected", this.element);

    this.mediaSupported = !!(navigator.mediaDevices && window.MediaRecorder);
    this.mediaRecorder = null;
    this.mediaStream = null;

    this.isRecording = false;
    this.audioChunks = [];
    this.recordedBlob = null;

    // 🔁 Transcription auto en fin d'enregistrement
    this.isTranscribing = false;

    // Firefox detection (simple)
    this.isFirefox = /firefox/i.test(navigator.userAgent);

    // WebAudio mode (Firefox)
    this.audioCtx = null;
    this.sourceNode = null;
    this.processorNode = null;

    // Ring buffer PCM (Float32Array chunks)
    this.ringBuffer = [];
    this.ringBufferSamples = 0;
    this.sampleRate = 48000; // sera corrigé par audioCtx.sampleRate
    this.isWebAudioRecording = false;

    // Boutons (état initial)
    this._setRecordButtonState(false);

    // Listener textarea => active/désactive Valider si l’utilisateur modifie
    if (this.hasTextareaTarget) {
      this._onTextareaInput = () => this._refreshValidateState();
      this.textareaTarget.addEventListener("input", this._onTextareaInput);
    }

    // État initial de Valider (souvent disabled)
    this._refreshValidateState();

    if (!this.mediaSupported && !this.isFirefox) {
      this.setStatus("⚠️ Enregistrement audio non supporté ici.");
      if (this.hasRecordButtonTarget) this.recordButtonTarget.disabled = true;
      return;
    }

    this.setStatus(
      this.isFirefox
        ? "Prêt. (Firefox) 🎙️ Enregistrer (re-clique pour stop) → transcription auto → ✅ Valider."
        : "Prêt. 🎙️ Enregistrer (re-clique pour stop) → transcription auto → ✅ Valider."
    );
  }

  disconnect() {
    try {
      if (this.isRecording) this.stopRecord();
      this.stopStream();
      this.stopWebAudioGraph();
    } catch {
      // no-op
    }

    if (this.hasTextareaTarget && this._onTextareaInput) {
      this.textareaTarget.removeEventListener("input", this._onTextareaInput);
    }
  }

  // =========================================================
  // ✅ Bouton unique : click->voice-input#toggle
  // =========================================================
  async toggle() {
    if (this.isTranscribing) return;

    if (this.isRecording) {
      this.stopRecord();
    } else {
      await this.startRecord();
    }
  }

  // (Compat si tu as encore du HTML qui appelle #start/#stop)
  async start() {
    if (!this.isRecording) await this.startRecord();
  }
  stop() {
    if (this.isRecording) this.stopRecord();
  }

  // ========== Common: stream ==========
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

  // ========== Record start/stop ==========
  async startRecord() {
    this.recordedBlob = null;

    // UI
    this.isRecording = true;
    this._setRecordButtonState(true);
    this._refreshValidateState();
    this.setStatus("⏳ Préparation…");

    if (this.isFirefox) {
      await this.startFirefoxWebAudio();
      return;
    }

    if (!this.mediaSupported) {
      this.setStatus("⚠️ MediaRecorder non supporté ici.");
      this.isRecording = false;
      this._setRecordButtonState(false);
      this._refreshValidateState();
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

        console.log("[voice-input] MediaRecorder blob", {
          type: this.recordedBlob.type,
          size: this.recordedBlob.size,
          chunks: this.audioChunks.length,
        });

        if (this.recordedBlob.size === 0) {
          this.setStatus("⚠️ Audio vide. Réessaie.");
          this._refreshValidateState();
          return;
        }

        // ✅ Auto-transcription dès que l'audio est prêt
        await this.autoTranscribeIntoTextarea();
      };

      this.mediaRecorder.start(250);
      this.beep();
      this.setStatus("🔴 Enregistrement… (re-clique pour arrêter)");
    } catch (e) {
      console.error("[voice-input] startRecord error", e);
      this.setStatus("⚠️ Micro refusé ou indisponible.");
      this.isRecording = false;
      this._setRecordButtonState(false);
      this._refreshValidateState();
      this.stopStream();
    }
  }

  stopRecord() {
    if (!this.isRecording) return;

    // UI
    this.isRecording = false;
    this._setRecordButtonState(false);
    this._refreshValidateState();
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
      console.error("[voice-input] stop error", e);
      this.setStatus("⚠️ Impossible d’arrêter l’enregistrement.");
    }
  }

  // ========== Firefox WebAudio recording ==========
  async startFirefoxWebAudio() {
    try {
      await this.ensureStream();

      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        this.setStatus("⚠️ WebAudio non supporté.");
        this.isRecording = false;
        this._setRecordButtonState(false);
        this._refreshValidateState();
        return;
      }

      if (!this.audioCtx) {
        this.audioCtx = new AudioCtx();
      }
      if (this.audioCtx.state === "suspended") {
        await this.audioCtx.resume();
      }

      this.sampleRate = this.audioCtx.sampleRate;

      this.sourceNode = this.audioCtx.createMediaStreamSource(this.mediaStream);

      const bufferSize = 4096;
      const numChannels = 1;
      this.processorNode = this.audioCtx.createScriptProcessor(
        bufferSize,
        numChannels,
        numChannels
      );

      // reset ring buffer
      this.ringBuffer = [];
      this.ringBufferSamples = 0;

      // collected recording buffers (Float32)
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
      console.error("[voice-input] Firefox WebAudio start error", e);
      this.setStatus("⚠️ Erreur démarrage audio (Firefox).");
      this.isWebAudioRecording = false;
      this.stopWebAudioGraph();
      this.isRecording = false;
      this._setRecordButtonState(false);
      this._refreshValidateState();
    }
  }

  stopFirefoxWebAudio() {
    try {
      this.isWebAudioRecording = false;

      // ✅ Pas de pré-roll (évite la duplication)
      const recorded = this.concatFloat32(this.recordBuffers, this.recordSamples);

      // Encode WAV 16-bit PCM (mono)
      const wavArrayBuffer = this.encodeWav16(recorded, this.sampleRate);
      this.recordedBlob = new Blob([wavArrayBuffer], { type: "audio/wav" });

      console.log("[voice-input] Firefox WAV blob", {
        type: this.recordedBlob.type,
        size: this.recordedBlob.size,
        sampleRate: this.sampleRate,
      });

      this.stopWebAudioGraph();

      // ✅ Auto-transcription
      this.autoTranscribeIntoTextarea().catch((e) => {
        console.error("[voice-input] auto transcribe (Firefox) failed", e);
      });
    } catch (e) {
      console.error("[voice-input] Firefox stop error", e);
      this.setStatus("⚠️ Erreur arrêt audio (Firefox).");
      this.stopWebAudioGraph();
      this._refreshValidateState();
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

  getRingAsLinear() {
    const total = this.ringBufferSamples;
    if (total <= 0) return new Float32Array(0);
    return this.concatFloat32(this.ringBuffer, total);
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

  // ========== "Valider" => parse uniquement ==========
  async send() {
    if (this.isTranscribing) return;

    const text = this.textareaTarget.value.trim();
    if (!text) {
      this.setStatus("⚠️ Aucun texte à valider. Enregistre ou saisis une phrase.");
      this._refreshValidateState();
      return;
    }

    // Pendant le parse, on évite double-clic
    if (this.hasValidateButtonTarget) this.validateButtonTarget.disabled = true;

    const ok = await this.parseText(text);

    // ✅ Si tout est OK : on vide le textarea
    if (ok) {
      this.textareaTarget.value = "";
    }

    // Refresh boutons (Valider repasse disabled si textarea vide)
    this._refreshValidateState();
  }

  // ✅ auto appelée en fin d'enregistrement
  async autoTranscribeIntoTextarea() {
    if (this.isTranscribing) return;

    if (!this.recordedBlob || this.recordedBlob.size === 0) {
      this.setStatus("⚠️ Audio vide. Réessaie.");
      this._refreshValidateState();
      return;
    }

    this.isTranscribing = true;
    this._refreshValidateState();
    if (this.hasRecordButtonTarget) this.recordButtonTarget.disabled = true;

    try {
      this.setStatus("⏳ Transcription…");
      const text = await this.transcribeAudio();
      if (!text) return;

      this.textareaTarget.value = text;

      // ✅ IMPORTANT : refresh juste après injection
      this._refreshValidateState();

      this.setStatus("✅ Transcription prête. Clique sur ✅ Valider.");
    } finally {
      this.isTranscribing = false;
      if (this.hasRecordButtonTarget) this.recordButtonTarget.disabled = false;

      // ✅ IMPORTANT : refresh à la sortie
      this._refreshValidateState();
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
      console.error("[voice-input] fetch transcribe failed", e);
      this.setStatus("❌ Erreur réseau transcription.");
      return null;
    }

    let data;
    try {
      data = await res.json();
    } catch (e) {
      console.error("[voice-input] invalid json from transcribe", e);
      this.setStatus("❌ Réponse transcription invalide.");
      return null;
    }

    if (!res.ok) {
      console.error("[voice-input] transcribe error", data);
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

  // ✅ Retourne true si OK (HTTP 2xx), sinon false
  async parseText(text) {
    this.setStatus("⏳ Parsing IA…");

    const payload = { text };
    if (this.hasUserIdValue && Number.isFinite(this.userIdValue)) {
      payload.user_id = this.userIdValue;
    }

    let res;
    try {
      res = await fetch(this.parseUrlValue, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
    } catch (e) {
      console.error("[voice-input] fetch parse failed", e);
      this.setStatus("❌ Erreur réseau parsing IA.");
      return false;
    }

    let data;
    try {
      data = await res.json();
    } catch (e) {
      console.error("[voice-input] invalid json from parse", e);
      this.setStatus("❌ Réponse parsing invalide.");
      return false;
    }

    if (!res.ok) {
      console.error("[voice-input] parse error", data);
      const msg = data?.error?.message || "Erreur /api/ai/parse.";
      this.setStatus(`❌ ${msg}`);
      return false;
    }

    this.setStatus("✅ Résultat reçu.");
    console.log("[voice-input] parse result", data);
    return true;
  }

  // ========== Helpers ==========
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

  _setRecordButtonState(isRecording) {
    if (!this.hasRecordButtonTarget) return;

    this.recordButtonTarget.textContent = isRecording ? "⏹️ Stop" : "🎙️ Enregistrer";
    this.recordButtonTarget.classList.toggle("btn-danger", isRecording);
    this.recordButtonTarget.classList.toggle("btn-primary", !isRecording);
    this.recordButtonTarget.setAttribute("aria-pressed", isRecording ? "true" : "false");
  }

  _refreshValidateState() {
    if (!this.hasValidateButtonTarget) return;

    const hasText = this.hasTextareaTarget && this.textareaTarget.value.trim().length > 0;
    const enabled = hasText && !this.isTranscribing;

    this.validateButtonTarget.disabled = !enabled;
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
