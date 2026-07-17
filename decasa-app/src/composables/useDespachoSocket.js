import { onBeforeUnmount } from 'vue'
import { useDespachoStore } from '@/stores/despacho'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

export function useDespachoSocket() {
  const despacho = useDespachoStore()
  const auth     = useAuthStore()
  const toast    = useToast()
  const subs     = []

  function conectar() {
    if (!window.Echo) return

    // Canal público de despacho — nuevas órdenes listas (supervisor)
    window.Echo.channel('despacho')
      .stopListening('.orden.lista_entrega')
      .listen('.orden.lista_entrega', (data) => {
        despacho.agregarACola(data)
        toast.success(`Nueva orden lista para entregar: ${data.cliente_nombre}`)
      })
    subs.push('despacho')

    // Canal del conductor — nuevas entregas asignadas
    if (auth.usuario?.rol === 'conductor') {
      window.Echo.channel(`conductor.${auth.usuario.id}`)
        .stopListening('.despacho.asignado')
        .listen('.despacho.asignado', (data) => {
          despacho.cargarCola()
          toast.info(`Tienes ${data.cantidad_ordenes} nueva(s) entrega(s) asignada(s)`)
        })
      subs.push(`conductor.${auth.usuario.id}`)
    }

    // Canal del supervisor — notificación de entregas completadas
    if (auth.usuario?.rol === 'supervisor') {
      window.Echo.channel('supervisor')
        .stopListening('.orden.entregada')
        .listen('.orden.entregada', (data) => {
          toast.success(`Orden #${data.numero_orden ?? data.orden_id} de ${data.cliente_nombre} fue entregada por ${data.conductor_nombre}`)
        })
      subs.push('supervisor')
    }
  }

  function desconectar() {
    if (!window.Echo) return
    subs.forEach((canal) => {
      window.Echo.leave(canal)
    })
    subs.length = 0
  }

  onBeforeUnmount(() => {
    desconectar()
  })

  return { conectar, desconectar }
}
