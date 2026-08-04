// Subir este número en cada cambio del service worker: al activarse borra los
// caches con otro nombre, y así no queda nada de la versión anterior.
const CACHE_NAME = 'decasa-v16'

// ── Push notifications ────────────────────────────────────────────────────────
self.addEventListener('push', (event) => {
  if (!event.data) return
  let payload
  try { payload = event.data.json() } catch { payload = { title: 'Decasa', body: event.data.text() } }

  const title   = payload.title ?? 'Decasa'
  const options = {
    body: payload.body ?? '',
    icon: '/logo_192x192.png',
    badge: '/logo_192x192.png',
    data: payload.datos ?? {},
    vibrate: [200, 100, 200],
  }
  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = '/'
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      const existing = list.find((c) => c.url.includes(self.location.origin))
      if (existing) {
        existing.focus()
        existing.postMessage({ type: 'push-click', datos: event.notification.data })
      } else {
        clients.openWindow(url)
      }
    })
  )
})

// ── Recursos del app shell a pre-cachear ─────────────────────────────────────
const SHELL_URLS = ['/', '/index.html']

// Instalar: pre-cachear el shell
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

// Activar: limpiar todos los caches viejos
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  )
  self.clients.claim()
})

// Verifica que la respuesta sea del tipo correcto antes de cachearla.
// Evita cachear index.html (text/html) cuando el browser pedía un .js o .css.
function esCacheableValida(request, response) {
  if (!response.ok) return false
  const contentType = response.headers.get('Content-Type') ?? ''
  const { pathname } = new URL(request.url)
  if (/\.(js|mjs)(\?|$)/.test(pathname) && !contentType.includes('javascript')) return false
  if (/\.css(\?|$)/.test(pathname) && !contentType.includes('css')) return false
  return true
}

/**
 * ¿Es el HTML de la app (no un archivo con hash en el nombre)?
 *
 * Importa la diferencia: los .js y .css llevan el hash en el nombre, así que
 * una vez publicados nunca cambian y se pueden servir del cache sin miedo. El
 * HTML no: es el que dice CUÁLES archivos cargar. Sirviéndolo del cache, tras
 * un despliegue la gente seguía viendo la versión anterior y tenía que recargar
 * dos veces para que apareciera lo nuevo.
 */
function esElHtml(request) {
  const { pathname } = new URL(request.url)
  return request.mode === 'navigate' || pathname === '/' || pathname === '/index.html'
}

// Fetch: network-first para /api y para el HTML; stale-while-revalidate para
// los archivos con hash, que no cambian.
self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  if (request.method !== 'GET') return
  if (url.pathname.startsWith('/api')) return

  event.respondWith(
    caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(request)
      const networkPromise = fetch(request)
        .then((res) => {
          if (esCacheableValida(request, res)) cache.put(request, res.clone())
          return res
        })
        .catch(() => null)

      // El HTML se pide siempre a la red primero. Solo se cae al cache si no
      // hay conexión, que es para lo que sirve tenerlo guardado.
      if (esElHtml(request)) {
        const res = await networkPromise
        if (res) return res
        if (cached) return cached
      } else if (cached) {
        networkPromise.catch(() => {})
        return cached
      }

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
