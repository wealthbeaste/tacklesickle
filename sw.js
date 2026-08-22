// TSCA Registry Service Worker
const CACHE_NAME = 'tsca-registry-v2';
const OFFLINE_URLS = [
  '/config.js',
  '/registry-admin.html',
  '/css/registry.css',
  '/css/styles.css',
  '/js/registry.js',
  '/js/registry-admin.js',
  '/images/logo.jpg'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(OFFLINE_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  event.respondWith(
    caches.match(event.request).then(cached => {
      const fetched = fetch(event.request).then(response => {
        if (response && response.status === 200) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => cached);

      return cached || fetched;
    })
  );
});

// Background sync queue for offline submissions
const DB_NAME = 'tsca-offline-queue';
const STORE_NAME = 'pending-requests';

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'QUEUE_REQUEST') {
    event.waitUntil(
      openDB().then(db => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).add({
          url: event.data.url,
          options: event.data.options,
          timestamp: Date.now()
        });
        return tx.complete;
      }).then(() => {
        self.registration.sync.register('sync-requests').catch(() => {});
      })
    );
  }
});

self.addEventListener('sync', event => {
  if (event.tag === 'sync-requests') {
    event.waitUntil(syncQueuedRequests());
  }
});

async function syncQueuedRequests() {
  const db = await openDB();
  const tx = db.transaction(STORE_NAME, 'readwrite');
  const store = tx.objectStore(STORE_NAME);
  const all = await new Promise((resolve, reject) => {
    const req = store.getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });

  for (const item of all) {
    try {
      const headers = new Headers(item.options.headers || {});
      headers.set('Content-Type', 'application/json');
      await fetch(item.url, { ...item.options, headers });
      store.delete(item.id);
    } catch (e) {
      // Will retry on next sync
    }
  }
  await tx.complete;
}
