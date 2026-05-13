import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getPendientesDespacho } from '@/api/produccion'

export const useDespachoProduccionStore = defineStore('despachoProduccion', () => {
  const pendientes = ref([])
  const pendientesCount = computed(() => pendientes.value.length)

  async function cargar() {
    try {
      const { data } = await getPendientesDespacho()
      pendientes.value = Array.isArray(data) ? data : []
    } catch {
      pendientes.value = []
    }
  }

  return { pendientes, pendientesCount, cargar }
})
