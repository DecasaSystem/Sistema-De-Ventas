import { onBeforeUnmount } from 'vue'

/**
 * Composable para suscribirse a canales de Reverb.
 * Limpia las suscripciones automáticamente al desmontar el componente.
 *
 * Uso:
 *   const { listen } = useRealtime()
 *   listen('ordenes', 'orden.actualizada', (data) => { ... })
 */
export function useRealtime() {
  const subs = [] // [{ channel, eventName }]

  function listen(channelName, eventName, callback) {
    if (!window.Echo) return
    window.Echo.channel(channelName).listen('.' + eventName, callback)
    subs.push({ channelName, eventName })
  }

  onBeforeUnmount(() => {
    const canalesVistos = new Set()
    subs.forEach(({ channelName, eventName }) => {
      window.Echo?.channel(channelName).stopListening('.' + eventName)
      canalesVistos.add(channelName)
    })
    canalesVistos.forEach(ch => window.Echo?.leave(ch))
  })

  return { listen }
}
