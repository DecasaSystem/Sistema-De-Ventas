import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const key = import.meta.env.VITE_PUSHER_APP_KEY

if (!key) {
  console.warn('[Echo] VITE_PUSHER_APP_KEY no definida — tiempo real desactivado.')
} else {
  window.Pusher = Pusher

  window.Echo = new Echo({
    broadcaster: 'pusher',
    key,
    cluster:     import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'us2',
    forceTLS:    true,
  })
}
