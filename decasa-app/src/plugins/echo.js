import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import api from '@/api'

const key = import.meta.env.VITE_REVERB_APP_KEY

if (!key) {
  console.warn('[Echo] VITE_REVERB_APP_KEY no definida — tiempo real desactivado (polling activo).')
} else {
  window.Pusher = Pusher

  window.Echo = new Echo({
    broadcaster:       'reverb',
    key,
    wsHost:            import.meta.env.VITE_REVERB_HOST,
    wsPort:            Number(import.meta.env.VITE_REVERB_PORT) || 80,
    wssPort:           Number(import.meta.env.VITE_REVERB_PORT) || 443,
    forceTLS:          (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    // Todos los canales son privados (2026-11-14): para escuchar uno, el
    // servidor pregunta quién es con la sesión. Se pide con la misma `api`
    // de siempre, que pone el token del perfil activo en ese momento (al
    // cambiar de perfil, los canales nuevos se piden con el nuevo).
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        api.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name }, { silencioso: true })
          .then(({ data }) => callback(null, data))
          .catch((error) => callback(error, null))
      },
    }),
  })
}
