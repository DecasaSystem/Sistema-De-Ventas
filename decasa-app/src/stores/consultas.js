import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getConteoConsultas } from '@/api/consultas'

/**
 * El número de consultas de costo pendientes que sale en el menú.
 *
 * Antes se descargaban TODAS las consultas —con sus ítems y desgloses, sin
 * límite— solo para contar las pendientes, cada vez que se abría la app. La
 * lista completa la pide la pantalla de Consultas cuando se entra a ella.
 */
export const useConsultasStore = defineStore('consultas', () => {
  const pendientesCount = ref(0)

  async function cargar() {
    try {
      const { data } = await getConteoConsultas()
      pendientesCount.value = Number(data?.pendientes) || 0
    } catch {
      pendientesCount.value = 0
    }
  }

  return { pendientesCount, cargar }
})
