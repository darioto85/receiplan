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

  static targets = ["toggle", "status"];

  async connect() {
    try {
      await this.refresh();
    } catch (e) {
      console.error("push-toggle connect error", e);
      this.setUiUnavailable("Erreur lors du chargement.");
    }
  }

  async getRegistration() {
    if (!("serviceWorker" in navigator)) return null;

    let reg = await navigator.serviceWorker.getRegistration();
    if (!reg) reg = await navigator.serviceWorker.register("/sw.js");

    return reg;
  }

  async refresh() {
    if (!("PushManager" in window) || !("Notification" in window)) {
      this.setUiUnavailable("Non supporté sur ce navigateur.");
      return;
    }

    const reg = await this.getRegistration();
    if (!reg) {
      this.setUiUnavailable("Service worker indisponible.");
      return;
    }

    if (Notification.permission === "denied") {
      // On ne peut pas activer via JS si l’utilisateur a “bloqué”
      this.toggleTarget.checked = false;
      this.toggleTarget.disabled = true;
      this.setStatus("Bloquées dans le navigateur.");
      return;
    }

    const sub = await reg.pushManager.getSubscription();
    this.toggleTarget.checked = !!sub;
    this.toggleTarget.disabled = false;
    this.setStatus(sub ? "Activées ✅" : "Désactivées");
  }

  async toggleChanged() {
    // évite double clic pendant traitement
    this.toggleTarget.disabled = true;

    try {
      if (this.toggleTarget.checked) {
        await this.enablePush();
      } else {
        await this.disablePush();
      }
    } catch (e) {
      console.error("push-toggle error", e);
      // rollback UI (on revient à l’état réel)
      await this.refresh();
      alert("Erreur lors de la mise à jour des notifications push.");
      return;
    } finally {
      // refresh remet aussi disabled=false sauf cas denied/not supported
      await this.refresh();
    }
  }

  async enablePush() {
    const permission = await Notification.requestPermission();
    if (permission !== "granted") {
      // l’utilisateur a refusé -> on repassera à OFF dans refresh()
      return;
    }

    const reg = await this.getRegistration();
    if (!reg) throw new Error("No SW registration");

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

    if (!res.ok) throw new Error(await res.text());
  }

  async disablePush() {
    const reg = await this.getRegistration();
    if (!reg) throw new Error("No SW registration");

    const sub = await reg.pushManager.getSubscription();
    if (!sub) return;

    // 1) navigateur
    await sub.unsubscribe();

    // 2) backend
    await fetch(this.unsubscribeUrlValue, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ endpoint: sub.endpoint }),
    });
  }

  setUiUnavailable(message) {
    if (this.hasToggleTarget) {
      this.toggleTarget.checked = false;
      this.toggleTarget.disabled = true;
    }
    this.setStatus(message);
  }

  setStatus(text) {
    if (this.hasStatusTarget) this.statusTarget.textContent = text;
  }
}
