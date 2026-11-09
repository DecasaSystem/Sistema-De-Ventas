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

  /**
   * Trae la primera página y la JUNTA con lo que ya se ve; no la reemplaza.
   *
   * Antes se pedía una sola vez al abrir la app y cada pedido pisaba la lista:
   *  - en el celular la app queda abierta días; el WebSocket se cae en segundo
   *    plano y lo que llegaba mientras tanto solo salía en el push. Al abrir la
   *    app desde el push, la campana no lo tenía: parecía que se había borrado;
   *  - un aviso que entraba por el WebSocket mientras la lista venía en camino
   *    desaparecía al llegar la respuesta (que se armó antes de ese aviso);
   *  - lo de "Ver anteriores" se perdía.
   * Ahora: lo de la página manda (leída o no, por si se leyó en otro aparato);
   * lo más nuevo que la respuesta (llegó en vivo) se queda; lo anterior a la
   * página (las de "Ver anteriores") también. Solo sale lo que estaba en el
   * rango de la página y el servidor ya no trae: lo borró la persona en otro aparato.
   */
  let pidiendo = null
  function cargar() {
    if (pidiendo) return pidiendo
    pidiendo = (async () => {
      const { data } = await notificacionesApi.listar()
      const pagina = data.items ?? []
      const ids    = new Set(pagina.map(n => n.id))
      // La página es "las 50 más nuevas"; las urgentes de atrás vienen aparte y no marcan el rango.
      const rango  = pagina.filter(n => !data.siguiente || n.id >= data.siguiente).map(n => n.id)
      const desde  = Math.min(...rango)
      const hasta  = Math.max(...rango)

      // Sin ninguna en el servidor: las borró todas (en otro aparato).
      const quedan = !rango.length ? [] : items.value.filter(n =>
        !ids.has(n.id) && (n.id > hasta || (data.siguiente && n.id < desde)))
      items.value = [...pagina, ...quedan].sort((a, b) => b.id - a.id)
      // Si ya se habían pedido las anteriores, se sigue desde donde se iba.
      if (!siguiente.value || !quedan.some(n => n.id < desde)) siguiente.value = data.siguiente
      noLeidasServidor.value = data.no_leidas
      recalcularAtras()
    })().finally(() => { pidiendo = null })
    return pidiendo
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
