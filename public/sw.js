self.addEventListener("push", (event) => {
  let data = {
    title: "Receiplan",
    body: "",
    url: "/meal-plan",
  };

  if (event.data) {
    try {
      const json = event.data.json();
      if (json && typeof json === "object") data = { ...data, ...json };
    } catch (e) {
      try {
        const text = event.data.text();
        if (text) data.body = text;
      } catch (e2) {}
    }
  }

  event.waitUntil(
    self.registration.showNotification(data.title || "Receiplan", {
      body: data.body || "",
      data: { url: data.url || "/meal-plan" },
      requireInteraction: true,
      // icon: "/img/icons/icon-192.png", // optionnel
      // badge: "/img/icons/badge-72.png", // optionnel
    })
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = event.notification?.data?.url || "/meal-plan";

  event.waitUntil(
    (async () => {
      const allClients = await clients.matchAll({ type: "window", includeUncontrolled: true });
      for (const c of allClients) {
        if ("focus" in c) {
          c.navigate(url);
          return c.focus();
        }
      }
      return clients.openWindow(url);
    })()
  );
});
