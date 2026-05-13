import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getSurtidosPendientes } from '@/api/surtidos'

export const useSurtidosStore = defineStore('surtidos', () => {
  const pendientes      = ref([])
  const pendientesCount = ref(0)

  async function cargarPendientes() {
    try {
      const { data } = await getSurtidosPendientes()
      pendientes.value      = data
      pendientesCount.value = data.length
    } catch {}
  }

  function incrementarPendientes() {
    pendientesCount.value++
  }

  function decrementarPendientes() {
    if (pendientesCount.value > 0) pendientesCount.value--
  }

  function quitarPendiente(surtidoTiendaId) {
    pendientes.value      = pendientes.value.filter(p => p.id !== surtidoTiendaId)
    pendientesCount.value = pendientes.value.length
  }

  return {
    pendientes,
    pendientesCount,
    cargarPendientes,
    incrementarPendientes,
    decrementarPendientes,
    quitarPendiente,
  }
})
