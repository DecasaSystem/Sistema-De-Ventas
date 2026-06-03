import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { colaDespacho, asignados, misEntregas } from '@/api/despacho'

export const useDespachoStore = defineStore('despacho', () => {
  const cola              = ref([])
  const asignadosArr      = ref([])
  const pendientes        = ref(0)
  const misEntregasArr    = ref([])

  const ordenesPendientes       = computed(() => cola.value.length)
  const misEntregasPendientes   = computed(() =>
    misEntregasArr.value.filter(e => e.estado !== 'entregado').length
  )

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

  async function cargarMisEntregas() {
    try {
      const { data } = await misEntregas()
      misEntregasArr.value = Array.isArray(data) ? data : []
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
    misEntregasArr,
    ordenesPendientes,
    misEntregasPendientes,
    cargarCola,
    cargarAsignados,
    cargarMisEntregas,
    refrescar,
    agregarACola,
    quitarDeCola,
  }
})
