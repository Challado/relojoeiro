// O service worker do Web Push (Minha conta, "Receber notificações neste aparelho"): mostra a notificação que chega do
// servidor ({titulo, texto, link}, cifrada pelo navegador) e, no clique, abre o sistema (o link, ou o Hoje).
self.addEventListener("push", function (ev) {
  var d = {};
  try {
    d = ev.data ? ev.data.json() : {};
  } catch (e) {
    d = {texto: ev.data ? ev.data.text() : ""};
  }
  ev.waitUntil(self.registration.showNotification(d.titulo || "Relojoeiro", {body: d.texto || "", data: {link: d.link || "index.php"}}));
});

self.addEventListener("notificationclick", function (ev) {
  ev.notification.close();
  var link = (ev.notification.data && ev.notification.data.link) || "index.php";
  ev.waitUntil(self.clients.matchAll({type: "window"}).then(function (abertas) {
    for (var i = 0; i < abertas.length; i++) {
      if (abertas[i].url.indexOf(link) === 0 && "focus" in abertas[i]) {
        return abertas[i].focus();
      }
    }
    return self.clients.openWindow(link);
  }));
});
