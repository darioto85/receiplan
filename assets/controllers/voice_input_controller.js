import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["textarea", "status"];
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

    if (!this.mediaSupported && !this.isFirefox) {
      this.setStatus("⚠️ Enregistrement audio non supporté ici.");
    } else {
      this.setStatus(
        this.isFirefox
          ? "Prêt. (Firefox) ⏺️ Enregistrer puis ➤ Envoyer."
          : "Prêt. ⏺️ Enregistrer puis ➤ Envoyer."
      );
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

  async toggleRecord() {
    if (this.isRecording) {
      this.stopRecord();
    } else {
      await this.startRecord();
    }
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

    if (this.isFirefox) {
      // ✅ Firefox => WebAudio PCM + WAV (pré-roll)
      await this.startFirefoxWebAudio();
      this.isRecording = true;
      return;
    }

    // ✅ Chrome/Edge => MediaRecorder (ta voie)
    if (!this.mediaSupported) {
      this.setStatus("⚠️ MediaRecorder non supporté ici.");
      return;
    }

    try {
      this.audioChunks = [];
      this.setStatus("⏳ Préparation…");

      await this.ensureStream();

      const mimeType = this.pickAudioMimeType();
      this.mediaRecorder = new MediaRecorder(this.mediaStream, mimeType ? { mimeType } : undefined);

      this.mediaRecorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) this.audioChunks.push(e.data);
      };

      this.mediaRecorder.onstop = () => {
        const type = this.mediaRecorder?.mimeType || "audio/webm";
        this.recordedBlob = new Blob(this.audioChunks, { type });

        console.log("[voice-input] MediaRecorder blob", {
          type: this.recordedBlob.type,
          size: this.recordedBlob.size,
          chunks: this.audioChunks.length,
        });

        if (this.recordedBlob.size === 0) {
          this.setStatus("⚠️ Audio vide. Réessaie.");
        } else {
          this.setStatus("⏳ Audio prêt. Clique sur ➤ Envoyer.");
        }
        // On garde le stream (warm-up) tant qu’on est sur la page
      };

      this.mediaRecorder.start(250);
      this.beep();
      this.isRecording = true;
      this.setStatus("🔴 Enregistrement…");
    } catch (e) {
      console.error("[voice-input] startRecord error", e);
      this.setStatus("⚠️ Micro refusé ou indisponible.");
      this.isRecording = false;
      this.stopStream();
    }
  }

  stopRecord() {
    if (!this.isRecording) return;

    if (this.isFirefox) {
      this.stopFirefoxWebAudio();
      this.isRecording = false;
      return;
    }

    if (!this.mediaRecorder) {
      this.isRecording = false;
      return;
    }

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

    this.isRecording = false;
  }

  // ========== Firefox WebAudio recording ==========
  async startFirefoxWebAudio() {
    try {
      this.setStatus("⏳ Préparation (Firefox)…");
      await this.ensureStream();

      // AudioContext doit souvent être "resume" suite à geste utilisateur
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        this.setStatus("⚠️ WebAudio non supporté.");
        return;
      }

      if (!this.audioCtx) {
        this.audioCtx = new AudioCtx();
      }
      if (this.audioCtx.state === "suspended") {
        await this.audioCtx.resume();
      }

      this.sampleRate = this.audioCtx.sampleRate;

      // Build graph
      this.sourceNode = this.audioCtx.createMediaStreamSource(this.mediaStream);

      // ScriptProcessorNode (deprecated mais toujours supporté, simple, OK ici)
      const bufferSize = 4096;
      const numChannels = 1;
      this.processorNode = this.audioCtx.createScriptProcessor(bufferSize, numChannels, numChannels);

      // reset ring buffer
      this.ringBuffer = [];
      this.ringBufferSamples = 0;

      // collected recording buffers (Float32)
      this.recordBuffers = [];
      this.recordSamples = 0;
      this.isWebAudioRecording = true;

      this.processorNode.onaudioprocess = (event) => {
        const input = event.inputBuffer.getChannelData(0);
        // Copie (sinon la mémoire se réutilise)
        const chunk = new Float32Array(input.length);
        chunk.set(input);

        // Ring buffer (pré-roll)
        this.pushToRing(chunk);

        // Pendant l’enregistrement, on accumule aussi
        if (this.isWebAudioRecording) {
          this.recordBuffers.push(chunk);
          this.recordSamples += chunk.length;
        }
      };

      // Connect (on peut connecter vers destination avec gain=0, mais pas nécessaire)
      this.sourceNode.connect(this.processorNode);
      this.processorNode.connect(this.audioCtx.destination);

      this.beep();
      this.setStatus("🔴 Enregistrement (Firefox)…");
    } catch (e) {
      console.error("[voice-input] Firefox WebAudio start error", e);
      this.setStatus("⚠️ Erreur démarrage audio (Firefox).");
      this.isWebAudioRecording = false;
      this.stopWebAudioGraph();
    }
  }

  stopFirefoxWebAudio() {
    try {
      this.isWebAudioRecording = false;

      // Pré-roll = on prend ring buffer + recording
      const preroll = this.getRingAsLinear();
      const recorded = this.concatFloat32(this.recordBuffers, this.recordSamples);

      const full = this.concatTwoFloat32(preroll, recorded);

      // Encode WAV 16-bit PCM (mono)
      const wavArrayBuffer = this.encodeWav16(full, this.sampleRate);
      this.recordedBlob = new Blob([wavArrayBuffer], { type: "audio/wav" });

      console.log("[voice-input] Firefox WAV blob", {
        type: this.recordedBlob.type,
        size: this.recordedBlob.size,
        sampleRate: this.sampleRate,
        prerollSeconds: this.prerollSecondsValue,
      });

      this.setStatus("⏳ Audio prêt. Clique sur ➤ Envoyer.");
      // On peut garder le graphe warm-up, mais on le coupe pour éviter CPU.
      this.stopWebAudioGraph();
    } catch (e) {
      console.error("[voice-input] Firefox stop error", e);
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

  concatTwoFloat32(a, b) {
    const out = new Float32Array(a.length + b.length);
    out.set(a, 0);
    out.set(b, a.length);
    return out;
  }

  encodeWav16(float32Samples, sampleRate) {
    // PCM 16-bit little-endian, mono
    const numChannels = 1;
    const bytesPerSample = 2;
    const blockAlign = numChannels * bytesPerSample;
    const byteRate = sampleRate * blockAlign;
    const dataSize = float32Samples.length * bytesPerSample;

    const buffer = new ArrayBuffer(44 + dataSize);
    const view = new DataView(buffer);

    // RIFF header
    this.writeString(view, 0, "RIFF");
    view.setUint32(4, 36 + dataSize, true);
    this.writeString(view, 8, "WAVE");

    // fmt chunk
    this.writeString(view, 12, "fmt ");
    view.setUint32(16, 16, true); // PCM
    view.setUint16(20, 1, true); // PCM
    view.setUint16(22, numChannels, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, byteRate, true);
    view.setUint16(32, blockAlign, true);
    view.setUint16(34, 16, true); // bits per sample

    // data chunk
    this.writeString(view, 36, "data");
    view.setUint32(40, dataSize, true);

    // PCM samples
    let offset = 44;
    for (let i = 0; i < float32Samples.length; i++) {
      let s = float32Samples[i];
      // clamp
      s = Math.max(-1, Math.min(1, s));
      // convert to int16
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

  // ========== Send / transcribe / parse ==========
  async send() {
    const typedText = this.textareaTarget.value.trim();
    if (typedText) {
      await this.parseText(typedText);
      return;
    }

    if (!this.recordedBlob || this.recordedBlob.size === 0) {
      this.setStatus("⚠️ Aucun texte ni audio à envoyer.");
      return;
    }

    const text = await this.transcribeAudio();
    if (!text) return;

    this.textareaTarget.value = text;
    await this.parseText(text);
  }

  async transcribeAudio() {
    this.setStatus("⏳ Transcription…");

    const fd = new FormData();

    // Firefox => WAV
    // Chrome => webm/ogg
    const mime = this.recordedBlob.type || "audio/webm";
    const ext =
      mime.includes("wav") ? "wav" :
      mime.includes("ogg") ? "ogg" :
      "webm";

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

    this.setStatus("✅ Transcription OK.");
    return text;
  }

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
      return;
    }

    let data;
    try {
      data = await res.json();
    } catch (e) {
      console.error("[voice-input] invalid json from parse", e);
      this.setStatus("❌ Réponse parsing invalide.");
      return;
    }

    if (!res.ok) {
      console.error("[voice-input] parse error", data);
      const msg = data?.error?.message || "Erreur /api/ai/parse.";
      this.setStatus(`❌ ${msg}`);
      return;
    }

    this.setStatus("✅ Résultat reçu.");
    console.log("[voice-input] parse result", data);
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
