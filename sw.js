// O service worker do aplicativo no celular (PWA). Chega pela API (api.php?recurso=sw), que põe na frente a VERSAO (muda
// quando qualquer arquivo das telas muda) e a lista ARQUIVOS (o estilo, os scripts, os ícones e a página sem conexão, com o
// ?v= que as páginas usam). O que ele faz:
//   1. Na instalação, guarda esses arquivos num cache com o nome da versão; ao ativar, apaga os caches das versões antigas.
//   2. O estilo, os scripts, as imagens e os textos do idioma (api.php?recurso=textos&formato=js) saem do cache quando já
//      estão nele (o endereço leva a versão: um arquivo novo tem outro endereço); senão vêm da rede e entram no cache.
//   3. As páginas vêm sempre da rede (o login e os dados são de agora); sem rede, a página "sem conexão" (offline.html).
//   4. A API nunca fica em cache: todo pedido vai para a rede.
//   5. O Web Push (Minha conta, "Receber notificações neste aparelho"): mostra a notificação que chega do servidor
//      ({titulo, texto, link}, cifrada pelo navegador) e, no clique, abre o sistema (o link, ou o Hoje).
var CACHE = "relojoeiro-" + VERSAO;

self.addEventListener("install", function (ev) {
  ev.waitUntil(caches.open(CACHE).then(function (c) {
    return c.addAll(ARQUIVOS);
  }).then(function () {
    return self.skipWaiting();
  }));
});

self.addEventListener("activate", function (ev) {
  ev.waitUntil(caches.keys().then(function (nomes) {
    return Promise.all(nomes.filter(function (n) { return n !== CACHE; }).map(function (n) { return caches.delete(n); }));
  }).then(function () {
    return self.clients.claim();
  }));
});

self.addEventListener("fetch", function (ev) {
  var pedido = ev.request;
  if (pedido.method !== "GET") {
    return;
  }
  var url = new URL(pedido.url);
  if (url.origin !== self.location.origin) {
    return;
  }
  var textos = /api\.php$/.test(url.pathname) && url.search.indexOf("recurso=textos") >= 0 && url.search.indexOf("formato=js") >= 0;
  var estatico = textos || /\.(css|js|png|svg|ico|woff2?)$/.test(url.pathname);
  if (estatico) {
    ev.respondWith(caches.match(pedido).then(function (guardado) {
      return guardado || fetch(pedido).then(function (resposta) {
        if (resposta.ok) {
          var copia = resposta.clone();
          caches.open(CACHE).then(function (c) { c.put(pedido, copia); });
        }
        return resposta;
      });
    }));
  } else if (pedido.mode === "navigate") {
    ev.respondWith(fetch(pedido).catch(function () {
      return caches.match("offline.html");
    }));
  }
});

self.addEventListener("push", function (ev) {
  var d = {};
  try {
    d = ev.data ? ev.data.json() : {};
  } catch (e) {
    d = {texto: ev.data ? ev.data.text() : ""};
  }
  ev.waitUntil(self.registration.showNotification(d.titulo || "Relojoeiro", {body: d.texto || "", icon: "icone-192.png", badge: "icone-192.png", data: {link: d.link || "index.php"}}));
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
