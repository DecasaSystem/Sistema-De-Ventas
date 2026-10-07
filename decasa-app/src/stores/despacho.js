import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { colaDespacho, conteoColaDespacho, asignados, misEntregas } from '@/api/despacho'

export const useDespachoStore = defineStore('despacho', () => {
  const cola              = ref([])
  const asignadosArr      = ref([])
  const pendientes        = ref(0)
  const misEntregasArr    = ref([])

  // El número del menú. Mientras no se haya entrado a Despacho sale de un
  // conteo liviano; una vez cargada la cola, de la cola misma, que es la que
  // se mantiene al día con los cambios.
  const conteoCola              = ref(0)
  const colaCargada             = ref(false)
  const ordenesPendientes       = computed(() =>
    colaCargada.value ? cola.value.length : conteoCola.value
  )
  const misEntregasPendientes   = computed(() =>
    misEntregasArr.value.filter(e => e.estado !== 'entregado').length
  )

  async function cargarCola() {
    try {
      const { data } = await colaDespacho()
      cola.value = data
      colaCargada.value = true
    } catch {}
  }

  /** Solo cuántas hay en la cola: para el menú, sin bajar la cola entera. */
  async function cargarConteo() {
    try {
      const { data } = await conteoColaDespacho()
      conteoCola.value = Number(data?.total) || 0
    } catch {}
  }

  async function cargarAsignados() {
    try {
      const { data } = await asignados()
      asignadosArr.value = data
    } catch {}
  }

  async function cargarMisEntregas() {
    try {
      const { data } = await misEntregas()
      misEntregasArr.value = Array.isArray(data) ? data : []
    } catch {}
  }

  async function refrescar() {
    await Promise.all([cargarCola(), cargarAsignados()])
  }

  // Llegó una orden lista por el socket: el aviso trae lo básico, sin los
  // productos ni cuánto falta de cada uno, y la cola necesita eso para
  // marcar qué va en el camión. Se recarga de la API en vez de pegar lo que
  // llegó.
  async function agregarACola(orden) {
    const idx = cola.value.findIndex(o => o.id === (orden.orden_id ?? orden.id))
    if (idx === -1) {
      await cargarCola()
    }
  }

  function quitarDeCola(ordenId) {
    cola.value = cola.value.filter(o => o.id !== ordenId)
  }

  return {
    cola,
    asignadosArr,
    pendientes,
    misEntregasArr,
    ordenesPendientes,
    misEntregasPendientes,
    cargarCola,
    cargarConteo,
    cargarAsignados,
    cargarMisEntregas,
    refrescar,
    agregarACola,
    quitarDeCola,
  }
})
