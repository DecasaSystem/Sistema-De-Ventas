import { ref } from 'vue'

/**
 * Visor único de fotos (VisorFoto.vue, montado en App.vue). Cualquier pantalla
 * abre aquí una foto en grande con los botones Descargar (JPG) y Compartir:
 *
 *   const { abrirFoto } = useVisorFoto()
 *   abrirFoto(producto.foto_url, { nombre: producto.nombre })
 *   abrirFoto([url1, url2], { indice: 1, nombre: 'Soporte' })
 *
 * Los enlaces a fotos (<a href="…jpg" target="_blank">) también caen aquí solos:
 * App.vue los intercepta. Para que un enlace siga abriendo pestaña nueva, ponerle
 * `data-sin-visor`.
 */
const visor = ref(null) // { fotos: string[], indice, nombre, detalle }

export function useVisorFoto() {
  function abrirFoto(fotos, { indice = 0, nombre = '', detalle = '' } = {}) {
    const lista = (Array.isArray(fotos) ? fotos : [fotos]).filter(Boolean)
    if (!lista.length) return
    visor.value = { fotos: lista, indice: Math.min(Math.max(indice, 0), lista.length - 1), nombre, detalle }
  }

  function cerrarFoto() {
    visor.value = null
  }

  return { visor, abrirFoto, cerrarFoto }
}
