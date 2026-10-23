import { ref, watch, onBeforeUnmount } from 'vue'
import { guardarBorrador, leerBorrador, borrarBorrador } from '@/utils/borradoresLocales'
import {
  leerBorradorNube, guardarBorradorNube, borrarBorradorNube,
  guardarBorradorNubeAlSalir, subirFotoBorrador,
} from '@/api/borradoresEnCurso'
import { idDeAparato, nombreDeAparato } from '@/utils/aparato'

/**
 * Que un formulario largo sobreviva a que se cierre la app, y se pueda seguir
 * en otro aparato.
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
 * Con `nube`, además se guarda una copia en la cuenta (BorradorEnCursoController).
 * Antes cada aparato tenía solo la suya: se recargaba en el celular, se seguía
 * en el computador y el computador ofrecía SU copia vieja; y al crear la orden
 * en uno, el otro la seguía ofreciendo (se podía crear dos veces). Ahora:
 *  - Al abrir se mira la copia del aparato y la de la cuenta, y se ofrece la
 *    más nueva diciendo dónde se guardó ("en el celular hace 3 min").
 *  - Cada guardado en la cuenta dice sobre qué revisión trabajó. Si otro
 *    aparato guardó o terminó la orden después, no se pisa: `conflicto` le
 *    pregunta a la persona con cuál se queda.
 *  - Al crear la orden (o empezar de cero) se borra en todos lados.
 *  - Las fotos se suben una vez a Cloudinary y la copia de la cuenta lleva el
 *    enlace; el otro aparato las baja al seguir.
 * Sin señal todo sigue funcionando con la copia del aparato.
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
 * @param {boolean}  [o.nube]        Guardar también en la cuenta, para seguir en otro aparato.
 * @param {Function} [o.noAntesDe]   async () => ms | null. Una copia guardada antes de eso ya
 *                                   no vale (ej. el borrador del servidor se guardó después).
 */
export function useBorradorLocal({ clave, fuentes, capturar, hayContenido, resumir, restaurar, omitir = [], nube = false, noAntesDe = null }) {
  const pendiente = ref(null)   // { guardadoEn, resumen, aparato } mientras se decide qué hacer
  const conflicto = ref(null)   // { tipo: 'editada'|'terminada', aparato, guardadoEn } si otro aparato siguió

  const llavesOmitidas = new Set(omitir)
  const miAparato = idDeAparato()
  let activo      = false       // ya se sabe qué había: se puede guardar
  let terminado   = false       // la orden se creó: no volver a guardar
  let sucio       = false
  let hayGuardado = false
  let timer       = null
  let primerCambioEn = 0
  let elegido     = null        // { origen: 'local'|'nube', ... } lo que se ofrece al volver
  let cola        = Promise.resolve()
  let pedidoPersistente = false
  const guardadas = new Set()   // ids de fotos que ya están en IndexedDB

  // Copia en la cuenta
  let revisionBase  = null      // última revisión de la cuenta que este aparato conoce (null = no sabe)
  let pendienteNube = false     // hay cambios que la cuenta todavía no tiene
  let nubePausada   = false     // hay un conflicto sin resolver: no se sube nada
  let ultimo        = null      // { datos, resumen, fotos } lo último que se guardó en el aparato
  let ultimoRegistro = null
  let timerNube     = null
  let primerCambioNubeEn = 0
  let colaNube      = Promise.resolve()
  let datosConflicto = null
  const fotosNube   = new Map() // id de foto → enlace en Cloudinary

  if (clave) {
    iniciar()

    watch(fuentes, programar, { deep: true })
    document.addEventListener('visibilitychange', alCambiarVisibilidad)
    window.addEventListener('pagehide', alIrse)
    window.addEventListener('beforeunload', alSalir)

    onBeforeUnmount(() => {
      guardarSiHayCambios()
      subirNube()
      document.removeEventListener('visibilitychange', alCambiarVisibilidad)
      window.removeEventListener('pagehide', alIrse)
      window.removeEventListener('beforeunload', alSalir)
    })
  }

  async function iniciar() {
    const [local, remoto, limite] = await Promise.all([
      leerBorrador(clave).catch((e) => {
        // Sin IndexedDB (modo privado viejo, almacenamiento lleno) el
        // formulario sigue funcionando igual, con la copia de la cuenta.
        console.warn('[borrador] No se pudo leer el borrador local:', e?.message ?? e)
        return null
      }),
      nube ? leerBorradorNube(clave).catch(() => null) : null,   // null = sin señal: se sigue con lo del aparato
      noAntesDe ? Promise.resolve().then(noAntesDe).catch(() => null) : null,
    ])

    let L = local?.registro ? local : null
    if (L && (L.registro.version !== VERSION || Date.now() - (L.registro.guardadoEn ?? 0) > CADUCIDAD_MS)) {
      await borrarBorrador(clave).catch(() => {})
      L = null
    }
    const S = remoto
    const tS = S?.guardado_at ? Date.parse(S.guardado_at) : 0

    let eleccion = null
    if (L) {
      const base = L.registro.revisionBase ?? null
      if (!S) {
        eleccion = 'local'
        revisionBase = base
      } else if (S.existe) {
        // Lo último en la cuenta lo puso este mismo aparato (o es lo que ya
        // tenía): lo de aquí está igual o más nuevo y trae las fotos.
        if (S.aparato_id === miAparato || base === S.revision) eleccion = 'local'
        // Nunca alcanzó a subirse y es más nuevo que lo de la cuenta.
        else if (base === null && L.registro.guardadoEn > tS) eleccion = 'local'
        else eleccion = 'nube'
        revisionBase = S.revision
      } else {
        revisionBase = S.revision
        const tEliminado = S.eliminado_at ? Date.parse(S.eliminado_at) : 0
        const terminadaEnOtro = S.eliminado && (base !== null ? base < S.revision : tEliminado > L.registro.guardadoEn)
        if (terminadaEnOtro) {
          // La orden se creó (o se empezó de cero) en otro aparato: ofrecerla
          // aquí era la puerta para crearla dos veces.
          await borrarBorrador(clave).catch(() => {})
          L = null
        } else {
          eleccion = 'local'
        }
      }
    } else if (S) {
      revisionBase = S.revision
      if (S.existe) eleccion = 'nube'
    }

    // El borrador del servidor se guardó después de esta copia: lo de la
    // copia ya está viejo y pisaría lo nuevo.
    const tEleccion = eleccion === 'local' ? L.registro.guardadoEn : tS
    if (eleccion && limite && tEleccion < limite) {
      if (eleccion === 'local') await borrarBorrador(clave).catch(() => {})
      eleccion = null
    }

    if (!eleccion) return activar()

    if (eleccion === 'local') {
      for (const [id, url] of Object.entries(L.registro.fotosNube ?? {})) fotosNube.set(id, url)
      elegido = { origen: 'local', local: L }
      pendiente.value = { guardadoEn: L.registro.guardadoEn, resumen: L.registro.resumen ?? null, aparato: null }
      // Lo último que hizo este aparato quizá no alcanzó a llegar a la cuenta
      // (Android cerró la app): se sube ya, por si se sigue en otro sin
      // contestar aquí. Solo si no pisa lo que otro aparato guardó.
      if (nube && L.registro.pendienteNube && (!S?.existe || S.aparato_id === miAparato || L.registro.revisionBase === S.revision)) {
        ultimo = { datos: L.registro.datos, resumen: L.registro.resumen ?? null, fotos: L.fotos }
        pendienteNube = true
        subirNube()
      }
    } else {
      elegido = { origen: 'nube', remoto: S }
      pendiente.value = {
        guardadoEn: tS,
        resumen:    S.resumen ?? null,
        aparato:    S.aparato_id === miAparato ? null : (S.aparato || 'otro aparato'),
      }
    }
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
    if (document.visibilityState === 'hidden') {
      guardarSiHayCambios()
      subirNubeAlSalir()
    } else {
      revisarOtroAparato()
    }
  }

  function alIrse() {
    guardarSiHayCambios()
    subirNubeAlSalir()
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
    subirNubeAlSalir()
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
      ultimo = null
      pendienteNube = false
      if (nube && !nubePausada) {
        colaNube = colaNube.then(() => borrarBorradorNube(clave))
          .then((r) => { revisionBase = r.revision })
          .catch(() => {})
      }
      return encolar(() => borrarBorrador(clave))
    }

    const fotos = new Map()
    let datos, resumen
    try {
      resumen = resumir?.() ?? null
      datos   = aPlano(capturar(), fotos, llavesOmitidas)
    } catch (e) {
      console.warn('[borrador] No se pudo armar el borrador:', e?.message ?? e)
      return cola
    }
    hayGuardado = true
    ultimo = { datos, resumen, fotos }
    pendienteNube = true
    const registro = armarRegistro()

    if (!pedidoPersistente) {
      pedidoPersistente = true
      // Que el navegador no lo borre cuando le falte espacio. Si no lo
      // concede, se guarda igual.
      navigator.storage?.persist?.().catch(() => {})
    }

    programarNube()
    return encolar(async () => {
      if (terminado) return
      const nuevas = new Map([...fotos].filter(([id]) => !guardadas.has(id)))
      await guardarBorrador(clave, registro, nuevas, new Set(fotos.keys()))
      for (const id of nuevas.keys()) guardadas.add(id)
      for (const id of [...guardadas]) if (!fotos.has(id)) guardadas.delete(id)
    })
  }

  /** Lo que se escribe en el aparato: el formulario y en qué va con la cuenta. */
  function armarRegistro() {
    ultimoRegistro = {
      version:       VERSION,
      guardadoEn:    Date.now(),
      resumen:       ultimo.resumen,
      datos:         ultimo.datos,
      revisionBase,
      pendienteNube,
      fotosNube:     Object.fromEntries(fotosNube),
    }
    return ultimoRegistro
  }

  function encolar(tarea) {
    cola = cola.then(tarea).catch((e) => {
      console.warn('[borrador] No se pudo guardar el borrador local:', e?.message ?? e)
    })
    return cola
  }

  // ── Copia en la cuenta ──────────────────────────────────────────────────────

  function programarNube() {
    if (!nube || nubePausada || terminado) return
    if (!primerCambioNubeEn) primerCambioNubeEn = Date.now()
    clearTimeout(timerNube)
    const espera = Math.min(NUBE_ESPERA_MS, Math.max(0, primerCambioNubeEn + NUBE_MAX_ESPERA_MS - Date.now()))
    timerNube = setTimeout(subirNube, espera)
  }

  function cuerpoNube(snap, extra = {}) {
    return {
      revision_base: revisionBase,
      datos:         conEnlaces(snap.datos, fotosNube),
      resumen:       snap.resumen,
      aparato_id:    miAparato,
      aparato:       nombreDeAparato(),
      ...extra,
    }
  }

  /** Sube lo último a la cuenta (en cola: nunca dos a la vez). */
  function subirNube() {
    clearTimeout(timerNube)
    timerNube = null
    primerCambioNubeEn = 0
    if (!nube || !clave) return colaNube
    colaNube = colaNube.then(async () => {
      if (!pendienteNube || nubePausada || terminado || !ultimo) return
      const snap = ultimo
      // Cada foto una sola vez. La que no suba (sin señal) se intenta en el
      // próximo guardado; mientras, el otro aparato la recibe sin esa foto.
      for (const [id, blob] of snap.fotos) {
        if (fotosNube.has(id)) continue
        try { fotosNube.set(id, await subirFotoBorrador(blob)) }
        catch (e) { console.warn('[borrador] No se pudo subir una foto del borrador:', e?.message ?? e) }
        if (terminado) return
      }
      try {
        const r = await guardarBorradorNube(clave, cuerpoNube(snap))
        revisionBase = r.revision
        if (ultimo === snap) {
          pendienteNube = false
          if ([...snap.fotos.keys()].some(id => !fotosNube.has(id))) pendienteNube = true
          reescribirRegistro()
        }
      } catch (e) {
        if (e.response?.status === 409) aConflicto(e.response.data, true)
        else console.warn('[borrador] No se pudo guardar el borrador en la cuenta:', e?.message ?? e)
      }
    })
    return colaNube
  }

  /** Lo de la cuenta cambió (revisión, fotos subidas): que el aparato lo recuerde. */
  function reescribirRegistro() {
    if (!ultimoRegistro || terminado) return
    const registro = { ...ultimoRegistro, revisionBase, pendienteNube, fotosNube: Object.fromEntries(fotosNube) }
    ultimoRegistro = registro
    encolar(() => guardarBorrador(clave, registro, new Map(), new Set(ultimo?.fotos?.keys() ?? [])))
  }

  /** Al irse la página: lo que haya, sin fotos nuevas (no da tiempo de subirlas). */
  function subirNubeAlSalir() {
    if (!nube || !activo || terminado || nubePausada || !pendienteNube || !ultimo) return
    guardarBorradorNubeAlSalir(clave, cuerpoNube(ultimo))
    // La respuesta no se alcanza a leer: la revisión que dio se recupera al
    // volver (la última la puso este aparato, así que no es un conflicto).
  }

  /** Al volver a la app: ¿otro aparato siguió la orden mientras tanto? */
  async function revisarOtroAparato() {
    if (!nube || !activo || terminado || nubePausada || conflicto.value || revisionBase === null) return
    try {
      const S = await leerBorradorNube(clave)
      if (S.revision !== revisionBase) aConflicto(S, false)
    } catch { /* sin señal: se revisa la próxima vez */ }
  }

  function aConflicto(S, alGuardar) {
    // La última la puso este mismo aparato (el guardado de "al salir", que no
    // alcanzó a leer la respuesta): no es otro, se sigue encima.
    if (S.aparato_id === miAparato && !S.eliminado) {
      revisionBase = S.revision
      if (alGuardar) { pendienteNube = true; programarNube() }
      return
    }
    // Vencida en la cuenta y nadie la borró: se guarda encima sin preguntar.
    if (!S.existe && !S.eliminado) {
      revisionBase = S.revision
      if (alGuardar) { pendienteNube = true; programarNube() }
      return
    }
    nubePausada = true
    datosConflicto = S
    conflicto.value = {
      tipo:       S.eliminado ? 'terminada' : 'editada',
      aparato:    S.eliminado ? null : (S.aparato || 'otro aparato'),
      guardadoEn: Date.parse(S.eliminado ? S.eliminado_at : S.guardado_at) || Date.now(),
    }
  }

  /** Conflicto: traer lo que se hizo en el otro aparato (lo de aquí se reemplaza). */
  async function traerDelOtro() {
    const S = datosConflicto
    if (!S?.datos) return quedarmeConEste()
    const datos = await revivirNube(S.datos, fotosNube)
    revisionBase = S.revision
    datosConflicto = null
    conflicto.value = null
    nubePausada = false
    pendienteNube = false
    try {
      await restaurar(datos)
    } catch (e) {
      console.warn('[borrador] No se pudo traer el borrador del otro aparato:', e?.message ?? e)
    }
  }

  /** Conflicto: seguir con lo de este aparato (pasa a ser lo de la cuenta). */
  function quedarmeConEste() {
    revisionBase = datosConflicto?.revision ?? revisionBase
    datosConflicto = null
    conflicto.value = null
    nubePausada = false
    pendienteNube = !!ultimo
    return subirNube()
  }

  /** Conflicto: la orden ya se terminó en otro aparato y aquí se suelta. */
  function soltar() {
    terminado = true
    clearTimeout(timer)
    clearTimeout(timerNube)
    datosConflicto = null
    conflicto.value = null
    return encolar(() => borrarBorrador(clave))
  }

  // ── Al volver ───────────────────────────────────────────────────────────────

  /** Volver a poner en el formulario lo que había. */
  async function continuar() {
    const e = elegido
    elegido = null
    pendiente.value = null
    if (!e) return activar()
    try {
      if (e.origen === 'local') {
        const r = e.local
        for (const id of r.fotos.keys()) guardadas.add(id)
        hayGuardado = true
        await restaurar(revivir(r.registro.datos, r.fotos))
      } else {
        revisionBase = e.remoto.revision
        await restaurar(await revivirNube(e.remoto.datos, fotosNube))
      }
    } catch (err) {
      console.warn('[borrador] No se pudo recuperar todo el borrador:', err?.message ?? err)
    }
    activar()
  }

  /** Empezar de cero: lo anterior se borra del teléfono y de la cuenta. */
  async function descartar() {
    elegido = null
    pendiente.value = null
    if (nube) {
      try { revisionBase = (await borrarBorradorNube(clave)).revision } catch { /* sin señal */ }
    }
    await encolar(() => borrarBorrador(clave))
    activar()
  }

  /**
   * La orden ya se creó: borrar el respaldo (aquí y en la cuenta, para que
   * ningún otro aparato la vuelva a ofrecer) y no volver a guardar (al salir
   * de la pantalla se desmonta y eso, si no, lo guardaría otra vez).
   */
  function borrar() {
    terminado = true
    clearTimeout(timer)
    clearTimeout(timerNube)
    if (!clave) return Promise.resolve()
    if (nube) {
      // Después de lo que esté subiendo, para que no la reviva.
      colaNube = colaNube.then(() => borrarBorradorNube(clave)).catch((e) => {
        console.warn('[borrador] No se pudo borrar el borrador de la cuenta:', e?.message ?? e)
      })
    }
    return encolar(() => borrarBorrador(clave))
  }

  return { pendiente, conflicto, continuar, descartar, borrar, guardarAhora, traerDelOtro, quedarmeConEste, soltar }
}

// Subir si cambia la forma de lo que se guarda: lo viejo se descarta en vez de
// meter datos que el formulario ya no entiende.
const VERSION       = 1
const ESPERA_MS     = 800
const MAX_ESPERA_MS = 5000
const CADUCIDAD_MS  = 48 * 60 * 60 * 1000
// A la cuenta va menos seguido: cada vez es una petición (y en el celular, datos).
const NUBE_ESPERA_MS     = 3000
const NUBE_MAX_ESPERA_MS = 15000

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

const esRefFoto = (v) => typeof v.__foto === 'string'

function revivir(v, fotos) {
  if (v === null || typeof v !== 'object') return v
  if (Array.isArray(v)) return v.map(x => revivir(x, fotos))
  if (esRefFoto(v)) {
    const blob = fotos.get(v.__foto) ?? null
    if (blob) idsDeFotos.set(blob, v.__foto)
    return blob
  }
  const out = {}
  for (const k of Object.keys(v)) out[k] = revivir(v[k], fotos)
  return out
}

/** Para la cuenta: cada foto con su enlace (el otro aparato la baja de ahí). */
function conEnlaces(v, fotosNube) {
  if (v === null || typeof v !== 'object') return v
  if (Array.isArray(v)) return v.map(x => conEnlaces(x, fotosNube))
  if (esRefFoto(v)) return { __foto: v.__foto, url: fotosNube.get(v.__foto) ?? null }
  const out = {}
  for (const k of Object.keys(v)) out[k] = conEnlaces(v[k], fotosNube)
  return out
}

/**
 * Lo de la cuenta de vuelta a datos del formulario: las fotos se bajan y
 * vuelven como Blob, igual que las del aparato. La que no se pueda bajar
 * queda en null (el formulario ya sabe quitar ese hueco).
 */
async function revivirNube(datos, fotosNube) {
  const enlaces = new Map()
  const recoger = (v) => {
    if (v === null || typeof v !== 'object') return
    if (Array.isArray(v)) return v.forEach(recoger)
    if (esRefFoto(v)) { if (v.url) enlaces.set(v.__foto, v.url); return }
    Object.values(v).forEach(recoger)
  }
  recoger(datos)

  const fotos = new Map()
  await Promise.all([...enlaces].map(async ([id, url]) => {
    try {
      const resp = await fetch(url, { mode: 'cors' })
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`)
      const blob = await resp.blob()
      // Como File, igual que la que sale del selector: al crear la orden se
      // sube con su nombre.
      const ext = blob.type.includes('png') ? 'png' : blob.type.includes('webp') ? 'webp' : 'jpg'
      fotos.set(id, new File([blob], `foto-${id}.${ext}`, { type: blob.type || 'image/jpeg' }))
      fotosNube.set(id, url)   // ya está arriba: no se vuelve a subir
    } catch (e) {
      console.warn('[borrador] No se pudo bajar una foto del borrador:', e?.message ?? e)
    }
  }))
  return revivir(datos, fotos)
}
