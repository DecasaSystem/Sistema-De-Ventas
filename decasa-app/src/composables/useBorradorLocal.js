import { ref, watch, onBeforeUnmount } from 'vue'
import { guardarBorrador, leerBorrador, borrarBorrador } from '@/utils/borradoresLocales'

/**
 * Que un formulario largo sobreviva a que se cierre la app.
 *
 * En un celular con poca memoria, Android mata la pestaña o el PWA que quedó
 * atrás mientras el vendedor está en WhatsApp o en la cámara; al volver, la
 * página arranca de cero. También pasa con una recarga sin querer. Eso no se
 * puede evitar, así que lo que se hace es que no cueste nada: el formulario se
 * va guardando en el teléfono y al volver se ofrece seguir donde iba.
 *
 * Cuándo se guarda, pensado para celulares lentos:
 *  - Poco después de que se deja de escribir (no en cada tecla), y como mucho
 *    cada MAX_ESPERA aunque se siga escribiendo sin parar.
 *  - Ya mismo al salir de la app (`visibilitychange` → hidden). Es el último
 *    aviso confiable: cuando Android mata la pestaña no corre nada más.
 *  - Las fotos, una sola vez; lo que se reescribe es el resto, que son KB.
 *
 * No se guarda nada hasta saber si había un borrador anterior: si no, el
 * formulario vacío lo pisaría antes de que el vendedor pueda recuperarlo.
 *
 * @param {object}   o
 * @param {string|null} o.clave      Una por usuario y formulario. null = apagado.
 * @param {Array}    o.fuentes       Refs a vigilar (cualquier cambio programa un guardado).
 * @param {Function} o.capturar      () => objeto con lo que se guarda (puede traer Blobs/Files).
 * @param {Function} o.hayContenido  () => bool. Sin contenido no se guarda nada.
 * @param {Function} o.resumir       () => { titulo, detalle } para el aviso al volver.
 * @param {Function} o.restaurar     async (datos) => vuelve a poner los datos en el formulario.
 * @param {string[]} [o.omitir]      Llaves que no se guardan en ningún nivel (vistas previas, banderas de carga…).
 */
export function useBorradorLocal({ clave, fuentes, capturar, hayContenido, resumir, restaurar, omitir = [] }) {
  const pendiente = ref(null)   // { guardadoEn, resumen } mientras se decide qué hacer

  const llavesOmitidas = new Set(omitir)
  let activo      = false       // ya se sabe qué había: se puede guardar
  let terminado   = false       // la orden se creó: no volver a guardar
  let sucio       = false
  let hayGuardado = false
  let timer       = null
  let primerCambioEn = 0
  let cargado     = null
  let cola        = Promise.resolve()
  let pedidoPersistente = false
  const guardadas = new Set()   // ids de fotos que ya están en IndexedDB

  if (clave) {
    leerBorrador(clave)
      .then(async (r) => {
        if (!r) return activar()
        const { registro } = r
        if (registro?.version !== VERSION || Date.now() - (registro.guardadoEn ?? 0) > CADUCIDAD_MS) {
          await borrarBorrador(clave).catch(() => {})
          return activar()
        }
        cargado = r
        pendiente.value = { guardadoEn: registro.guardadoEn, resumen: registro.resumen ?? null }
      })
      .catch((e) => {
        // Sin IndexedDB (modo privado viejo, almacenamiento lleno) el
        // formulario sigue funcionando igual que antes, solo sin respaldo.
        console.warn('[borrador] No se pudo leer el borrador local:', e?.message ?? e)
      })

    watch(fuentes, programar, { deep: true })
    document.addEventListener('visibilitychange', alCambiarVisibilidad)
    window.addEventListener('pagehide', guardarSiHayCambios)
    window.addEventListener('beforeunload', alSalir)

    onBeforeUnmount(() => {
      guardarSiHayCambios()
      document.removeEventListener('visibilitychange', alCambiarVisibilidad)
      window.removeEventListener('pagehide', guardarSiHayCambios)
      window.removeEventListener('beforeunload', alSalir)
    })
  }

  function activar() { activo = true }

  function programar() {
    if (!activo || terminado) return
    sucio = true
    if (!primerCambioEn) primerCambioEn = Date.now()
    clearTimeout(timer)
    const espera = Math.min(ESPERA_MS, Math.max(0, primerCambioEn + MAX_ESPERA_MS - Date.now()))
    timer = setTimeout(guardarAhora, espera)
  }

  function alCambiarVisibilidad() {
    if (document.visibilityState === 'hidden') guardarSiHayCambios()
  }

  function guardarSiHayCambios() {
    if (sucio) guardarAhora()
  }

  // Recargar o cerrar la pestaña con algo escrito: el navegador pregunta antes.
  // En el celular no siempre sale (y cuando Android mata la app, nunca), pero
  // para eso está el guardado; esto evita el F5 sin querer en el computador.
  function alSalir(e) {
    if (!activo || terminado || !hayContenido()) return
    guardarSiHayCambios()
    e.preventDefault()
    e.returnValue = ''
  }

  /**
   * Toma la foto del formulario ya (sincrónico, para que al salir de la app
   * quede lo último) y la escribe en cola: nunca dos escrituras a la vez.
   */
  function guardarAhora() {
    clearTimeout(timer)
    timer = null
    primerCambioEn = 0
    sucio = false
    if (!activo || terminado) return cola

    if (!hayContenido()) {
      if (!hayGuardado) return cola
      hayGuardado = false
      guardadas.clear()
      return encolar(() => borrarBorrador(clave))
    }

    const fotos = new Map()
    let registro
    try {
      registro = {
        version:    VERSION,
        guardadoEn: Date.now(),
        resumen:    resumir?.() ?? null,
        datos:      aPlano(capturar(), fotos, llavesOmitidas),
      }
    } catch (e) {
      console.warn('[borrador] No se pudo armar el borrador:', e?.message ?? e)
      return cola
    }
    hayGuardado = true

    if (!pedidoPersistente) {
      pedidoPersistente = true
      // Que el navegador no lo borre cuando le falte espacio. Si no lo
      // concede, se guarda igual.
      navigator.storage?.persist?.().catch(() => {})
    }

    return encolar(async () => {
      if (terminado) return
      const nuevas = new Map([...fotos].filter(([id]) => !guardadas.has(id)))
      await guardarBorrador(clave, registro, nuevas, new Set(fotos.keys()))
      for (const id of nuevas.keys()) guardadas.add(id)
      for (const id of [...guardadas]) if (!fotos.has(id)) guardadas.delete(id)
    })
  }

  function encolar(tarea) {
    cola = cola.then(tarea).catch((e) => {
      console.warn('[borrador] No se pudo guardar el borrador local:', e?.message ?? e)
    })
    return cola
  }

  /** Volver a poner en el formulario lo que había. */
  async function continuar() {
    const r = cargado
    cargado = null
    pendiente.value = null
    if (!r) return activar()
    for (const id of r.fotos.keys()) guardadas.add(id)
    hayGuardado = true
    try {
      await restaurar(revivir(r.registro.datos, r.fotos))
    } catch (e) {
      console.warn('[borrador] No se pudo recuperar todo el borrador:', e?.message ?? e)
    }
    activar()
  }

  /** Empezar de cero: lo anterior se borra del teléfono. */
  async function descartar() {
    cargado = null
    pendiente.value = null
    await encolar(() => borrarBorrador(clave))
    activar()
  }

  /**
   * La orden ya se creó: borrar el respaldo y no volver a guardar (al salir de
   * la pantalla se desmonta y eso, si no, lo guardaría otra vez).
   */
  function borrar() {
    terminado = true
    clearTimeout(timer)
    if (!clave) return Promise.resolve()
    return encolar(() => borrarBorrador(clave))
  }

  return { pendiente, continuar, descartar, borrar, guardarAhora }
}

// Subir si cambia la forma de lo que se guarda: lo viejo se descarta en vez de
// meter datos que el formulario ya no entiende.
const VERSION       = 1
const ESPERA_MS     = 800
const MAX_ESPERA_MS = 5000
const CADUCIDAD_MS  = 48 * 60 * 60 * 1000

// Cada foto (Blob/File) lleva un id fijo mientras viva, para guardarla una sola vez.
const idsDeFotos = new WeakMap()
let contador = 0

function idDeFoto(blob) {
  let id = idsDeFotos.get(blob)
  if (!id) {
    id = `${Date.now().toString(36)}${(contador++).toString(36)}${Math.random().toString(36).slice(2, 6)}`
    idsDeFotos.set(blob, id)
  }
  return id
}

/** A datos planos que IndexedDB pueda guardar: sin proxies de Vue ni funciones. */
function aPlano(v, fotos, omitir) {
  if (typeof v === 'function') return undefined
  if (v === null || typeof v !== 'object') return v
  if (v instanceof Blob) {
    const id = idDeFoto(v)
    fotos.set(id, v)
    return { __foto: id }
  }
  if (v instanceof Date) return v.toISOString()
  if (Array.isArray(v)) return v.map(x => aPlano(x, fotos, omitir) ?? null)
  const out = {}
  for (const k of Object.keys(v)) {
    if (omitir.has(k)) continue
    const x = aPlano(v[k], fotos, omitir)
    if (x !== undefined) out[k] = x
  }
  return out
}

function revivir(v, fotos) {
  if (v === null || typeof v !== 'object') return v
  if (Array.isArray(v)) return v.map(x => revivir(x, fotos))
  const llaves = Object.keys(v)
  if (llaves.length === 1 && llaves[0] === '__foto') {
    const blob = fotos.get(v.__foto) ?? null
    if (blob) idsDeFotos.set(blob, v.__foto)
    return blob
  }
  const out = {}
  for (const k of llaves) out[k] = revivir(v[k], fotos)
  return out
}
