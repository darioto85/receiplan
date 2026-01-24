import { Controller } from "@hotwired/stimulus";

function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
  return outputArray;
}

export default class extends Controller {
  static values = {
    vapidPublicKey: String,
    subscribeUrl: String,
    unsubscribeUrl: String,
  };

  static targets = ["status"];

  async connect() {
    console.log("✅ push connected");
    try {
      await this.refreshStatus();
    } catch (e) {
      console.error("❌ refreshStatus error", e);
      this.setStatus("Erreur lors du chargement du statut push.", false);
    }
  }

  async getRegistration() {
    if (!("serviceWorker" in navigator)) return null;

    // ✅ Ne bloque pas sur serviceWorker.ready
    let reg = await navigator.serviceWorker.getRegistration();
    if (!reg) {
      reg = await navigator.serviceWorker.register("/sw.js");
    }
    return reg;
  }

  async refreshStatus() {
    if (!("PushManager" in window) || !("Notification" in window)) {
      this.setStatus("Push non supporté sur ce navigateur.", false);
      return;
    }

    if (Notification.permission === "denied") {
      this.setStatus("Notifications bloquées dans le navigateur.", false);
      return;
    }

    const reg = await this.getRegistration();
    if (!reg) {
      this.setStatus("Service worker indisponible.", false);
      return;
    }

    const sub = await reg.pushManager.getSubscription();

    if (sub) {
      // ✅ Activé => l’encart disparaît définitivement
      this.element.remove();
      return;
    }

    this.setStatus("Notifications désactivées.", true);
  }

  async subscribe() {
    console.log("✅ subscribe clicked");

    if (!("PushManager" in window) || !("Notification" in window)) {
      alert("Les notifications push ne sont pas supportées sur ce navigateur.");
      return;
    }

    const permission = await Notification.requestPermission();
    if (permission !== "granted") {
      await this.refreshStatus();
      return;
    }

    const reg = await this.getRegistration();
    if (!reg) {
      alert("Impossible d’enregistrer le service worker.");
      return;
    }

    const existing = await reg.pushManager.getSubscription();
    const sub =
      existing ||
      (await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(this.vapidPublicKeyValue),
      }));

    const res = await fetch(this.subscribeUrlValue, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(sub),
    });

    if (!res.ok) {
      console.error("subscribe failed", await res.text());
      alert("Erreur lors de l’activation des notifications.");
      return;
    }

    // ✅ Après succès, remove définitif
    this.element.remove();
  }

  async unsubscribe() {
    // Normalement tu ne verras plus ce bouton si on remove,
    // mais je le laisse au cas où.
    const reg = await this.getRegistration();
    if (!reg) return;

    const sub = await reg.pushManager.getSubscription();
    if (!sub) {
      await this.refreshStatus();
      return;
    }

    await sub.unsubscribe();

    await fetch(this.unsubscribeUrlValue, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ endpoint: sub.endpoint }),
    });

    await this.refreshStatus();
  }

  setStatus(text) {
    if (!this.hasStatusTarget) return;
    this.statusTarget.textContent = text;
  }
}
