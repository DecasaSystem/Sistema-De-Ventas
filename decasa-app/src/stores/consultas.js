import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getConsultas } from '@/api/consultas'

export const useConsultasStore = defineStore('consultas', () => {
  const items = ref([])

  const pendientesCount = computed(() =>
    items.value.filter(c => c.estado === 'pendiente').length
  )

  async function cargar() {
    try {
      const { data } = await getConsultas()
      items.value = Array.isArray(data) ? data : []
    } catch {
      items.value = []
    }
  }

  return { items, pendientesCount, cargar }
})
