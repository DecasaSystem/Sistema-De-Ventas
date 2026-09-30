import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { notificacionesApi } from '@/api/notificaciones'

export const useNotificacionesStore = defineStore('notificaciones', () => {
  const items     = ref([])
  // El servidor manda de a 50. Las no leídas se cuentan allá: con solo lo
  // cargado, el globo decía 50 aunque hubiera 80 sin leer.
  const noLeidasServidor = ref(0)
  const siguiente = ref(null)          // desde dónde piden las anteriores; null = no hay más
  const cargandoMas = ref(false)

  // Lo que está cargado sin leer, más lo de atrás que todavía no se ha pedido
  const noLeidasAtras = ref(0)
  const noLeidas = computed(() => items.value.filter(n => !n.leida).length + noLeidasAtras.value)

  function recalcularAtras() {
    const cargadas = items.value.filter(n => !n.leida).length
    noLeidasAtras.value = Math.max(0, noLeidasServidor.value - cargadas)
  }

  async function cargar() {
    const { data } = await notificacionesApi.listar()
    items.value = data.items
    siguiente.value = data.siguiente
    noLeidasServidor.value = data.no_leidas
    recalcularAtras()
  }

  async function cargarMas() {
    if (!siguiente.value || cargandoMas.value) return
    cargandoMas.value = true
    try {
      const { data } = await notificacionesApi.listar(siguiente.value)
      const ya = new Set(items.value.map(n => n.id))
      items.value.push(...data.items.filter(n => !ya.has(n.id)))
      siguiente.value = data.siguiente
      noLeidasServidor.value = data.no_leidas
      recalcularAtras()
    } finally {
      cargandoMas.value = false
    }
  }

  function agregarNueva(n) {
    if (items.value.some(x => x.id === n.id)) return
    items.value.unshift(n)
  }

  async function leer(id) {
    await notificacionesApi.marcarLeida(id)
    const n = items.value.find(x => x.id === id)
    if (n) n.leida = true
  }

  async function leerTodas() {
    await notificacionesApi.marcarTodas()
    items.value.forEach(n => (n.leida = true))
    noLeidasAtras.value = 0
  }

  async function eliminar(id) {
    await notificacionesApi.eliminar(id)
    items.value = items.value.filter(n => n.id !== id)
  }

  async function eliminarTodas() {
    await notificacionesApi.eliminarTodas()
    items.value = []
    siguiente.value = null
    noLeidasAtras.value = 0
  }

  function limpiar() {
    items.value = []
    siguiente.value = null
    noLeidasAtras.value = 0
  }

  return {
    items, noLeidas, siguiente, cargandoMas,
    cargar, cargarMas, agregarNueva, leer, leerTodas, eliminar, eliminarTodas, limpiar,
  }
})
