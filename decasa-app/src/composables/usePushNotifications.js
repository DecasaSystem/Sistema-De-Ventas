import api from '@/api'

let registrado = false

/*
 * La llave pública del servidor no cambia: se recuerda en el aparato en vez
 * de pedirla en cada apertura (un viaje menos al servidor).
 */
const KEY_LLAVE = 'pushVapidKey'

function leer(clave) {
  try { return JSON.parse(localStorage.getItem(clave) ?? 'null') } catch { return null }
}
function guardar(clave, valor) {
  try { localStorage.setItem(clave, JSON.stringify(valor)) } catch { /* sin almacenamiento: se pide siempre */ }
}

async function llaveVapid(refrescar = false) {
  if (!refrescar) {
    const guardada = leer(KEY_LLAVE)
    if (guardada) return guardada
  }
  const { data } = await api.get('/push/vapid-key', { silencioso: true })
  if (data?.key) guardar(KEY_LLAVE, data.key)
  return data?.key ?? null
}

async function suscribir(registro, llave) {
  return registro.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(llave),
  })
}

export async function registrarPush() {
  if (registrado) return
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return

  try {
    let vapidPublicKey = await llaveVapid()
    if (!vapidPublicKey) return

    const registro = await navigator.serviceWorker.ready

    // Pedir permiso si no se ha dado
    const permiso = await Notification.requestPermission()
    if (permiso !== 'granted') return

    // Suscribir al push. Si falla con la llave recordada, puede que el
    // servidor la haya cambiado: se pide de nuevo y se intenta otra vez.
    let suscripcion
    try {
      suscripcion = await suscribir(registro, vapidPublicKey)
    } catch {
      vapidPublicKey = await llaveVapid(true)
      if (!vapidPublicKey) return
      suscripcion = await suscribir(registro, vapidPublicKey)
    }

    // Se manda en cada apertura a propósito: si en el servidor se borró (o es
    // otra persona en este aparato), así vuelve a quedar. Que los avisos
    // lleguen al celular pesa más que ahorrarse esta petición.
    const json = suscripcion.toJSON()
    await api.post('/push/subscribe', {
      endpoint:   json.endpoint,
      p256dh:     json.keys.p256dh,
      auth_token: json.keys.auth,
    }, { silencioso: true })

    registrado = true
  } catch (e) {
    console.warn('[Push] No se pudo registrar:', e?.message)
  }
}

export async function cancelarPush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return
  try {
    const registro     = await navigator.serviceWorker.ready
    const suscripcion  = await registro.pushManager.getSubscription()
    if (!suscripcion) return
    await api.delete('/push/subscribe', { data: { endpoint: suscripcion.endpoint } })
    await suscripcion.unsubscribe()
    registrado = false
  } catch (e) {
    console.warn('[Push] No se pudo cancelar:', e?.message)
  }
}

function urlBase64ToUint8Array(base64String) {
  const padding  = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64   = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw      = atob(base64)
  const output   = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i)
  return output
}
