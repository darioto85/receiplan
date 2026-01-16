self.addEventListener("push", (event) => {
  event.waitUntil((async () => {
    let data = {
      title: "Receiplan",
      body: "",
      url: "/meal-plan",

      icon: undefined,
      image: undefined,
      badge: undefined,

      tag: undefined,
      renotify: false,
      requireInteraction: false,

      actions: undefined,
      yesUrl: undefined,
      noUrl: undefined,
    };

    if (event.data) {
      try {
        const json = await event.data.json();
        if (json && typeof json === "object") data = { ...data, ...json };
      } catch (e) {
        try {
          const text = await event.data.text();
          if (text) data.body = text;
        } catch (e2) {}
      }
    }

    const options = {
      body: data.body || "",
      icon: data.icon || undefined,
      image: data.image || undefined,
      badge: data.badge || undefined,

      tag: data.tag || undefined,
      renotify: !!data.renotify,
      requireInteraction: !!data.requireInteraction,

      actions: Array.isArray(data.actions) ? data.actions : undefined,

      data: {
        url: data.url || "/meal-plan",
        yesUrl: data.yesUrl,
        noUrl: data.noUrl,
      },
    };

    console.log("[SW] push received", options);

    await self.registration.showNotification(data.title || "Receiplan", options);
  })());
});

self.addEventListener("notificationclick", (event) => {
  event.waitUntil((async () => {
    const notifData = event.notification?.data || {};
    const action = event.action || "";
    const origin = self.location.origin;

    // Convertit toute URL en absolu (plus fiable sur Windows)
    const toAbs = (u) => {
      if (!u) return null;
      try {
        return new URL(u, origin).href;
      } catch (_) {
        return null;
      }
    };

    const fallbackUrl = toAbs(notifData.url) || toAbs("/meal-plan");

    console.log("[SW] notificationclick", { action, notifData, fallbackUrl });

    // Toujours fermer après (sinon parfois le click bug visuellement)
    event.notification.close();

    // ✅ Boutons Oui/Non
    if (action === "yes" && notifData.yesUrl) {
      const yesUrl = toAbs(notifData.yesUrl);
      console.log("[SW] action YES ->", yesUrl);
      if (yesUrl) {
        try { await fetch(yesUrl, { method: "POST" }); } catch (_) {}
      }
      return;
    }

    if (action === "no" && notifData.noUrl) {
      const noUrl = toAbs(notifData.noUrl);
      console.log("[SW] action NO ->", noUrl);
      if (noUrl) {
        try { await fetch(noUrl, { method: "POST" }); } catch (_) {}
      }
      return;
    }

    // ✅ Clic sur la notif (fallback)
    if (!fallbackUrl) return;

    const allClients = await clients.matchAll({ type: "window", includeUncontrolled: true });
    console.log("[SW] clients:", allClients.length);

    for (const c of allClients) {
      if ("focus" in c) {
        try {
          if ("navigate" in c) await c.navigate(fallbackUrl);
        } catch (_) {}
        await c.focus();
        return;
      }
    }

    if (clients.openWindow) {
      await clients.openWindow(fallbackUrl);
    }
  })());
});
