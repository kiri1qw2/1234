// service-worker.js
const CACHE_NAME = 'okolo-cache-v1';
const urlsToCache = [
    '/',
    '/index.php',
    '/login.php',
    '/assets/css/style.css',
    '/assets/js/script.js',
    '/modules/ophthalmologist/dashboard.php',
    '/modules/ophthalmologist/patient_card.php'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(urlsToCache))
    );
});

self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request)
            .then(response => response || fetch(event.request))
    );
});