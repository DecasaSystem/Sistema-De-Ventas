import api from '@/api'

let registrado = false

export async function registrarPush() {
  if (registrado) return
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return

  try {
    const { data } = await api.get('/push/vapid-key')
    const vapidPublicKey = data.key
    if (!vapidPublicKey) return

    const registro = await navigator.serviceWorker.ready

    // Pedir permiso si no se ha dado
    const permiso = await Notification.requestPermission()
    if (permiso !== 'granted') return

    // Suscribir al push
    const suscripcion = await registro.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    })

    const json = suscripcion.toJSON()
    await api.post('/push/subscribe', {
      endpoint:   json.endpoint,
      p256dh:     json.keys.p256dh,
      auth_token: json.keys.auth,
    })

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
