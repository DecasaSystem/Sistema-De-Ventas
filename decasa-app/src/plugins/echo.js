import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const key = import.meta.env.VITE_REVERB_APP_KEY

if (!key) {
  console.warn('[Echo] VITE_REVERB_APP_KEY no definida — tiempo real desactivado.')
} else {
  window.Pusher = Pusher

  window.Echo = new Echo({
    broadcaster:       'reverb',
    key,
    wsHost:            import.meta.env.VITE_REVERB_HOST   ?? 'localhost',
    wsPort:            Number(import.meta.env.VITE_REVERB_PORT  ?? 8080),
    wssPort:           Number(import.meta.env.VITE_REVERB_PORT  ?? 8080),
    forceTLS:          (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats:      true,
  })
}
