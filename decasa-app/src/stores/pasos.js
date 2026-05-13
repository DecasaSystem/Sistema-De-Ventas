import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getMisPasos } from '@/api/produccion'

export const usePasosStore = defineStore('pasos', () => {
  const pasos = ref([])
  const pendientesCount = computed(() => pasos.value.length)

  async function cargar() {
    try {
      const { data } = await getMisPasos()
      pasos.value = Array.isArray(data) ? data : []
    } catch {
      pasos.value = []
    }
  }

  return { pasos, pendientesCount, cargar }
})
