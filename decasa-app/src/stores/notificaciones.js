import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { notificacionesApi } from '@/api/notificaciones'

export const useNotificacionesStore = defineStore('notificaciones', () => {
  const items    = ref([])
  const noLeidas = computed(() => items.value.filter(n => !n.leida).length)

  async function cargar() {
    const { data } = await notificacionesApi.listar()
    items.value = data
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
  }

  async function eliminar(id) {
    await notificacionesApi.eliminar(id)
    items.value = items.value.filter(n => n.id !== id)
  }

  async function eliminarTodas() {
    await notificacionesApi.eliminarTodas()
    items.value = []
  }

  function limpiar() {
    items.value = []
  }

  return { items, noLeidas, cargar, agregarNueva, leer, leerTodas, eliminar, eliminarTodas, limpiar }
})
