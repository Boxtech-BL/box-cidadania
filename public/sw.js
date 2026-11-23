// Service worker: guarda só CSS, JS e ícone. Páginas e dados sempre vêm da rede,
// para nunca mostrar pauta ou resultado desatualizado.
var CACHE = 'cidadania-v1';
var ARQUIVOS = ['assets/estilo.css', 'assets/app.js', 'assets/icone.svg'];

self.addEventListener('install', function (ev) {
  ev.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(ARQUIVOS); }));
  self.skipWaiting();
});

self.addEventListener('activate', function (ev) {
  ev.waitUntil(caches.keys().then(function (nomes) {
    return Promise.all(nomes.filter(function (n) { return n !== CACHE; }).map(function (n) { return caches.delete(n); }));
  }));
});

self.addEventListener('fetch', function (ev) {
  if (ev.request.method !== 'GET' || !/\/assets\//.test(new URL(ev.request.url).pathname)) return;
  ev.respondWith(fetch(ev.request).then(function (r) {
    var copia = r.clone();
    caches.open(CACHE).then(function (c) { c.put(ev.request, copia); });
    return r;
  }).catch(function () { return caches.match(ev.request); }));
});
