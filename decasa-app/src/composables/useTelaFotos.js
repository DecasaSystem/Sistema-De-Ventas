import { ref } from 'vue'
import api from '@/api'
import { cloudinaryOpt } from '@/utils/cloudinary'

// Mapa singleton { "marca|tipo|color": foto_url } cargado una sola vez para
// toda la app (evita peticiones repetidas en cada buscador de tela).
const fotos    = ref({})
let   cargado  = false
let   cargando = null

function claveTela(marca, tipo, color) {
  return [marca, tipo, color].map(v => (v ?? '').toString().trim().toLowerCase()).join('|')
}

async function cargarFotosTela(force = false) {
  if (cargado && !force) return fotos.value
  if (cargando) return cargando
  cargando = (async () => {
    try {
      const { data } = await api.get('/inventario-telas')
      const map = {}
      for (const t of (data ?? [])) {
        if (t.foto_url) map[claveTela(t.marca, t.tipo, t.color)] = t.foto_url
      }
      fotos.value = map
      cargado = true
    } catch {
      // silencioso: si falla, simplemente no se muestran fotos
    } finally {
      cargando = null
    }
    return fotos.value
  })()
  return cargando
}

/**
 * Devuelve la URL (optimizada) de la foto para una tela marca/tipo/color, o ''.
 */
function fotoDeTela(marca, tipo, color, width = 40) {
  const url = fotos.value[claveTela(marca, tipo, color)]
  return url ? cloudinaryOpt(url, width) : ''
}

/**
 * Construye un mapa { color: foto_url_optimizada } para una marca+tipo dados,
 * útil para pasarlo como `images` a un ComboInput de colores.
 */
function fotosPorColor(marca, tipo, colores, width = 40) {
  const out = {}
  for (const color of (colores ?? [])) {
    const f = fotoDeTela(marca, tipo, color, width)
    if (f) out[color] = f
  }
  return out
}

export function useTelaFotos() {
  return { fotos, cargarFotosTela, fotoDeTela, fotosPorColor }
}
