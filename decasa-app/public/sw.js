const CACHE_NAME = 'decasa-v1'

// Recursos del app shell a pre-cachear
const SHELL_URLS = ['/', '/index.html']

// Instalar: pre-cachear el shell (cada URL por separado para evitar que una falla bloquee todo)
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      Promise.allSettled(
        SHELL_URLS.map((url) =>
          cache.add(url).catch(() => console.warn('[SW] No se pudo cachear', url))
        )
      )
    )
  )
  self.skipWaiting()
})

// Activar: limpiar caches viejos
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  )
  self.clients.claim()
})

// Fetch: network-first para /api, cache-first para todo lo demás
self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  // Ignorar requests que no son GET
  if (request.method !== 'GET') return

  // API: siempre de la red (sin cache)
  if (url.pathname.startsWith('/api')) return

  // App shell y assets: stale-while-revalidate
  event.respondWith(
    caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(request)
      const networkPromise = fetch(request)
        .then((res) => {
          if (res.ok) cache.put(request, res.clone())
          return res
        })
        .catch(() => null)

      // Si tenemos cache, devolver inmediatamente y actualizar en background
      if (cached) {
        networkPromise.catch(() => {})
        return cached
      }

      // Sin cache: esperar la red; si falla y es navegación, devolver /
      const res = await networkPromise
      if (res) return res

      if (request.mode === 'navigate') {
        const root = await cache.match('/')
        if (root) return root
      }

      return new Response('Sin conexión', { status: 503 })
    })
  )
})
