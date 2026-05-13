import { onBeforeUnmount } from 'vue'
import { useSurtidosStore } from '@/stores/surtidos'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

export function useSurtidosSocket() {
  const surtidos = useSurtidosStore()
  const auth     = useAuthStore()
  const toast    = useToast()
  const subs     = []

  function conectar() {
    if (!window.Echo) return

    if (auth.usuario?.rol === 'vendedor') {
      const canal = `vendedor.${auth.usuario.id}`
      window.Echo.channel(canal)
        .stopListening('.surtido.enviado')
        .listen('.surtido.enviado', (data) => {
          surtidos.incrementarPendientes()
          surtidos.cargarPendientes()
          toast.info(`${data.supervisor_nombre} envió ${data.cantidad_productos} producto(s) para validar`)
        })
      subs.push(canal)
    }

    if (auth.isSupervisor) {
      const canal = `supervisor.${auth.usuario.id}`
      window.Echo.channel(canal)
        .stopListening('.surtido.aceptado')
        .stopListening('.surtido.rechazado')
        .listen('.surtido.aceptado', (data) => {
          toast.success(`${data.tienda_nombre} aceptó el surtido #${data.surtido_id} (${data.vendedor_nombre})`)
        })
        .listen('.surtido.rechazado', (data) => {
          toast.error(`${data.tienda_nombre} rechazó el surtido #${data.surtido_id}${data.motivo ? ': ' + data.motivo : ''}`)
        })
      subs.push(canal)
    }
  }

  function desconectar() {
    if (!window.Echo) return
    subs.forEach((canal) => window.Echo.leave(canal))
    subs.length = 0
  }

  onBeforeUnmount(desconectar)

  return { conectar, desconectar }
}
