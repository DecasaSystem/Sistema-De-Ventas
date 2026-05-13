import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { colaDespacho, asignados } from '@/api/despacho'

export const useDespachoStore = defineStore('despacho', () => {
  const cola         = ref([])
  const asignadosArr = ref([])
  const pendientes   = ref(0)

  const ordenesPendientes = computed(() => cola.value.length)

  async function cargarCola() {
    try {
      const { data } = await colaDespacho()
      cola.value = data
    } catch {}
  }

  async function cargarAsignados() {
    try {
      const { data } = await asignados()
      asignadosArr.value = data
    } catch {}
  }

  async function refrescar() {
    await Promise.all([cargarCola(), cargarAsignados()])
  }

  function agregarACola(orden) {
    const idx = cola.value.findIndex(o => o.id === orden.orden_id)
    if (idx === -1) {
      cola.value.push(orden)
    }
  }

  function quitarDeCola(ordenId) {
    cola.value = cola.value.filter(o => o.id !== ordenId)
  }

  return {
    cola,
    asignadosArr,
    pendientes,
    ordenesPendientes,
    cargarCola,
    cargarAsignados,
    refrescar,
    agregarACola,
    quitarDeCola,
  }
})
