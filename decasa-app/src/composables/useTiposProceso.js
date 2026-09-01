import { ref } from 'vue'
import api from '@/api'

/**
 * Los procesos del taller, traídos del catálogo que mantiene el supervisor.
 *
 * Antes eran listas fijas repetidas en cinco pantallas: agregar un proceso
 * obligaba a tocarlas todas y a desplegar. Ahora se piden una vez y se
 * comparten; quien los crea o los renombra es el taller.
 */
const tipos    = ref([])
// A quién se le puede asignar un proceso a dedo, aparte de por especialidad.
const trabajadores = ref([])
const colores  = ref([])
// ¿El taller lleva las restauraciones aparte de los muebles nuevos? Apagado,
// la línea de cada trabajador no decide nada y la pantalla ni la muestra.
const separaRestauraciones = ref(false)
const cargados = ref(false)
let enVuelo    = null

/** Clases de Tailwind por color. Van escritas enteras a propósito: si se
 *  armaran con plantillas (`bg-${color}-100`), el compilador no las vería y
 *  las borraría del CSS final. */
const CLASES = {
  orange: 'bg-orange-100 text-orange-700',
  teal:   'bg-teal-100 text-teal-700',
  indigo: 'bg-indigo-100 text-indigo-700',
  yellow: 'bg-yellow-100 text-yellow-700',
  purple: 'bg-purple-100 text-purple-700',
  pink:   'bg-pink-100 text-pink-700',
  rose:   'bg-rose-100 text-rose-700',
  stone:  'bg-stone-200 text-stone-700',
  blue:   'bg-blue-100 text-blue-700',
  green:  'bg-green-100 text-green-700',
  red:    'bg-red-100 text-red-700',
  slate:  'bg-slate-200 text-slate-700',
}

export function useTiposProceso() {
  async function cargar(forzar = false, incluirInactivos = false) {
    if (cargados.value && !forzar) return tipos.value
    if (enVuelo && !forzar) return enVuelo

    enVuelo = api.get('/tipos-proceso', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })
      .then(({ data }) => {
        tipos.value        = data.tipos ?? []
        trabajadores.value = data.trabajadores ?? []
        colores.value      = data.colores ?? []
        separaRestauraciones.value = !!data.separa_restauraciones
        cargados.value = true
        return tipos.value
      })
      .finally(() => { enVuelo = null })

    return enVuelo
  }

  /** El nombre de un proceso. Si el catálogo cambió y queda un paso viejo,
   *  se muestra su clave antes que dejar el hueco en blanco. */
  function nombre(clave) {
    return tipos.value.find(t => t.clave === clave)?.nombre ?? clave
  }

  function clases(clave) {
    const t = tipos.value.find(x => x.clave === clave)
    return CLASES[t?.color] ?? CLASES.slate
  }

  function clasesDeColor(color) {
    return CLASES[color] ?? CLASES.slate
  }

  return { tipos, trabajadores, colores, separaRestauraciones, cargados, cargar, nombre, clases, clasesDeColor, CLASES }
}
