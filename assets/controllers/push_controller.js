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
    // csrfToken: String, // on ajoutera plus tard
  };

  static targets = ["status"];

  async connect() {
    console.log("✅ push connected")
    // Enregistre le SW dès que possible
    await this.registerServiceWorker();
    await this.refreshStatus();
  }

  async registerServiceWorker() {
    if (!("serviceWorker" in navigator)) {
        console.log("❌ serviceWorker not supported");
        return null;
    }
    try {
        const reg = await navigator.serviceWorker.register("/sw.js");
        console.log("✅ SW registered", reg.scope);
        return reg;
    } catch (e) {
        console.error("❌ SW register failed", e);
        return null;
    }
    }

  async refreshStatus() {
    if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
      this.setStatus("Push non supporté sur ce navigateur.", false);
      return;
    }

    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();

    if (Notification.permission === "denied") {
      this.setStatus("Notifications bloquées dans le navigateur.", false);
      return;
    }

    if (sub) {
      this.setStatus("Notifications activées ✅", true);
    } else {
      this.setStatus("Notifications désactivées.", true);
    }
  }

  async subscribe() {
    console.log("✅ subscribe clicked")
    if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
      alert("Les notifications push ne sont pas supportées sur ce navigateur.");
      return;
    }

    // 1) Permission
    const permission = await Notification.requestPermission();
    if (permission !== "granted") {
      await this.refreshStatus();
      return;
    }

    // 2) Subscribe navigateur
    const reg = await navigator.serviceWorker.ready;
    const existing = await reg.pushManager.getSubscription();
    const sub =
      existing ||
      (await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(this.vapidPublicKeyValue),
      }));

    // 3) Envoi backend
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

    await this.refreshStatus();
  }

  async unsubscribe() {
    if (!("serviceWorker" in navigator)) return;

    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    if (!sub) {
      await this.refreshStatus();
      return;
    }

    // 1) Unsubscribe navigateur
    await sub.unsubscribe();

    // 2) Unsubscribe backend (on envoie juste l’endpoint)
    await fetch(this.unsubscribeUrlValue, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ endpoint: sub.endpoint }),
    });

    await this.refreshStatus();
  }

  setStatus(text, ok = true) {
    if (!this.hasStatusTarget) return;
    this.statusTarget.textContent = text;
    // pas de couleurs hardcodées ici, Bootstrap gère si tu veux
  }
}
