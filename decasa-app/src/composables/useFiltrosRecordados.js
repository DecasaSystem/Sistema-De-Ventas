import { watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Que una pantalla recuerde sus filtros mientras la app siga abierta.
 *
 * Entrar al detalle de una orden y volver desmontaba la lista, y al montarla
 * de nuevo los filtros arrancaban vacíos: había que ponerlos otra vez cada
 * vez. Ahora se guardan en sessionStorage y se recuperan al volver, se llegue
 * con "atrás", con el menú o con un botón de la pantalla.
 *
 * Es sessionStorage a propósito: al cerrar la pestaña o la app se olvidan, y
 * mañana la pantalla arranca limpia. Va por usuario, para que quien entra
 * después en el mismo equipo no herede los filtros del anterior.
 *
 * Llamarla justo después de declarar los refs y antes de cualquier `watch`
 * sobre ellos: así el valor recuperado no se toma como un cambio del usuario.
 *
 * @param {string} clave  Nombre de la pantalla ('ordenes', 'produccion'…).
 * @param {Record<string, import('vue').Ref>} refs  Lo que se recuerda.
 */
export function useFiltrosRecordados(clave, refs) {
  const auth = useAuthStore()
  const llave = `filtros:${auth.usuario?.id ?? 'anon'}:${clave}`

  let guardado = null
  try { guardado = JSON.parse(sessionStorage.getItem(llave) || 'null') } catch {}

  if (guardado && typeof guardado === 'object') {
    for (const [nombre, r] of Object.entries(refs)) {
      if (!(nombre in guardado)) continue
      const valor  = guardado[nombre]
      const actual = r.value

      if (esObjeto(actual)) {
        // Solo las llaves que la pantalla todavía conoce: si un filtro se
        // quitó o se renombró, lo viejo guardado no se cuela.
        if (!esObjeto(valor)) continue
        const limpio = {}
        for (const k of Object.keys(actual)) if (k in valor) limpio[k] = valor[k]
        r.value = { ...actual, ...limpio }
      } else if (valor === null || actual === null || typeof valor === typeof actual) {
        r.value = valor
      }
    }
  }

  watch(Object.values(refs), () => {
    const datos = {}
    for (const [nombre, r] of Object.entries(refs)) datos[nombre] = r.value
    try { sessionStorage.setItem(llave, JSON.stringify(datos)) } catch {}
  }, { deep: true })
}

function esObjeto(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v)
}
