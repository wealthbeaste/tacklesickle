// TSCA Registry Service Worker
const CACHE_NAME = 'tsca-registry-v9';
const OFFLINE_URLS = [
  '/',
  '/index.html',
  '/config.js',
  '/registry-admin.html',
  '/css/registry.css',
  '/css/styles.css',
  '/js/app.js',
  '/js/registry.js',
  '/js/registry-admin.js',
  '/images/logo.jpg'
];

const API_CACHE = 'tsca-api-v1';
const IMAGE_CACHE = 'tsca-images-v1';

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(OFFLINE_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== CACHE_NAME && k !== API_CACHE && k !== IMAGE_CACHE).map(k => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // API requests: network-first, cache fallback
  if (url.pathname.startsWith('/api/v1/')) {
    event.respondWith(
      fetch(event.request).then(response => {
        if (response && response.status === 200) {
          const clone = response.clone();
          caches.open(API_CACHE).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => caches.match(event.request))
    );
    return;
  }

  // Cross-origin requests (fonts, icons): skip
  if (url.origin !== self.location.origin) return;

  // Images: cache-first
  if (event.request.destination === 'image' || url.pathname.match(/\.(jpe?g|png|gif|svg|webp|ico)$/i)) {
    event.respondWith(
      caches.match(event.request).then(cached => {
        if (cached) return cached;
        return fetch(event.request).then(response => {
          if (response && response.status === 200) {
            const clone = response.clone();
            caches.open(IMAGE_CACHE).then(cache => cache.put(event.request, clone));
          }
          return response;
        }).catch(() => new Response('', { status: 408, statusText: 'Offline' }));
      })
    );
    return;
  }

  // Same-origin HTML/JS/CSS: stale-while-revalidate
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
      openDB().then(async db => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).add({
          url: event.data.url,
          options: event.data.options,
          timestamp: Date.now()
        });
        await tx.complete;

        // Notify all clients about queued request count
        const countTx = db.transaction(STORE_NAME, 'readonly');
        const count = await new Promise((resolve) => {
          const req = countTx.objectStore(STORE_NAME).count();
          req.onsuccess = () => resolve(req.result);
          req.onerror = () => resolve(0);
        });

        const clients = await self.clients.matchAll();
        clients.forEach(client => {
          client.postMessage({ type: 'QUEUE_COUNT', count });
        });

        // Try to register background sync
        if ('sync' in self.registration) {
          self.registration.sync.register('sync-requests').catch(() => {});
        }
      })
    );
  }

  if (event.data && event.data.type === 'GET_QUEUE_COUNT') {
    event.waitUntil(
      openDB().then(async db => {
        const tx = db.transaction(STORE_NAME, 'readonly');
        const count = await new Promise((resolve) => {
          const req = tx.objectStore(STORE_NAME).count();
          req.onsuccess = () => resolve(req.result);
          req.onerror = () => resolve(0);
        });
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
          client.postMessage({ type: 'QUEUE_COUNT', count });
        });
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

  let synced = 0;
  for (const item of all) {
    try {
      const headers = new Headers(item.options.headers || {});
      headers.set('Content-Type', 'application/json');
      await fetch(item.url, { ...item.options, headers });
      store.delete(item.id);
      synced++;
    } catch (e) {
      // Will retry on next sync
    }
  }
  await tx.complete;

  // Notify clients about sync completion
  if (synced > 0) {
    const clients = await self.clients.matchAll();
    const remaining = all.length - synced;
    clients.forEach(client => {
      client.postMessage({ type: 'SYNC_COMPLETE', synced, remaining });
    });
  }
}
