import { ref } from 'vue'
import api from '@/api'
import { TELAS_CATALOGO, marcasOrdenadas, tiposTelaDeM, coloresDeTela } from '@/data/telasCatalogo'

// Metros libres por tela, cargados una sola vez para toda la app. Antes cada
// pantalla que pedía tela repetía este mapa y sus tres filtros; si uno cambiaba,
// los demás quedaban distintos.
const telaMetrosMap = ref({})
let   cargado  = false
let   cargando = null

async function cargarTelas(force = false) {
  if (cargado && !force) return telaMetrosMap.value
  if (cargando) return cargando
  cargando = (async () => {
    try {
      const { data } = await api.get('/inventario-telas')
      const map = {}
      for (const t of (data ?? [])) {
        map[`${t.marca}|${t.tipo}|${t.color}`] = t.metros_libres
      }
      telaMetrosMap.value = map
      cargado = true
    } catch {
      // silencioso: sin inventario simplemente no se ofrece ninguna tela
    } finally {
      cargando = null
    }
    return telaMetrosMap.value
  })()
  return cargando
}

/** Metros libres de una tela concreta (0 si no está en inventario). */
function metrosDeTela(marca, tipo, color) {
  return telaMetrosMap.value[`${marca}|${tipo}|${color}`] ?? 0
}

function tieneStock(marca, tipo, color) {
  return metrosDeTela(marca, tipo, color) > 0
}

// Solo se ofrece lo que de verdad hay: prometerle al cliente una tela agotada
// termina en una orden que el taller no puede empezar.
function marcasConStock() {
  return marcasOrdenadas.value.filter(m =>
    Object.keys(TELAS_CATALOGO[m] ?? {}).some(tipo =>
      (TELAS_CATALOGO[m][tipo] ?? []).some(color => tieneStock(m, tipo, color))
    )
  )
}

function tiposConStock(marca) {
  return tiposTelaDeM(marca).filter(tipo =>
    (TELAS_CATALOGO[marca]?.[tipo] ?? []).some(color => tieneStock(marca, tipo, color))
  )
}

function coloresConStock(marca, tipo) {
  return coloresDeTela(marca, tipo).filter(color => tieneStock(marca, tipo, color))
}

export function useTelas() {
  return {
    telaMetrosMap,
    cargarTelas,
    metrosDeTela,
    tieneStock,
    marcasConStock,
    tiposConStock,
    coloresConStock,
  }
}
