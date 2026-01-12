self.addEventListener("push", (event) => {
  event.waitUntil((async () => {
    let data = {
      title: "Receiplan",
      body: "",
      url: "/meal-plan",
      image: undefined,
      tag: undefined,
      renotify: false,
    };

    if (event.data) {
      try {
        const json = await event.data.json(); // ✅ IMPORTANT
        if (json && typeof json === "object") {
          data = { ...data, ...json };
        }
      } catch (e) {
        try {
          const text = await event.data.text(); // ✅ IMPORTANT
          if (text) data.body = text;
        } catch (e2) {}
      }
    }

    await self.registration.showNotification(data.title || "Receiplan", {
      body: data.body || "",
      icon: data.icon || undefined,   // ✅
      image: data.image || undefined, // ✅
      tag: data.tag || undefined,
      data: { url: data.url || "/meal-plan" },
    });
  })());
});


self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = event.notification?.data?.url || "/meal-plan";

  event.waitUntil((async () => {
    const allClients = await clients.matchAll({ type: "window", includeUncontrolled: true });

    for (const c of allClients) {
      // Si une fenêtre existe déjà, on la focus et on navigue
      if ("focus" in c) {
        try {
          if ("navigate" in c) await c.navigate(url);
        } catch (_) {}
        await c.focus();
        return;
      }
    }

    // Sinon on en ouvre une nouvelle
    if (clients.openWindow) {
      await clients.openWindow(url);
    }
  })());
});
