// Service worker minimal : juste ce qu'il faut pour que l'app soit installable (PWA).
// Aucune mise en cache de pages/données : c'est un outil d'admin avec des données live,
// un contenu périmé affiché par erreur serait pire qu'un simple passthrough réseau.
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});
