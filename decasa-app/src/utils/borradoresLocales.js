/**
 * Formularios a medio llenar guardados en el propio teléfono (IndexedDB).
 *
 * Es IndexedDB y no localStorage por dos razones: guarda las fotos tal cual
 * (localStorage solo guarda texto, cabe ~5 MB y una foto en base64 pesa un
 * tercio más), y escribe sin trabar la pantalla (localStorage es síncrono: en
 * un celular lento, guardar mientras se escribe se nota).
 *
 * Dos almacenes:
 *  - `borradores`: el formulario, ya en datos planos. Cada foto va como una
 *    referencia { __foto: id }. Pesa pocos KB y es lo que se reescribe.
 *  - `fotos`: cada foto una sola vez, con llave `<clave>|<id>`. Una foto no
 *    cambia nunca, así que no se vuelve a escribir en cada guardado.
 */

const DB_NOMBRE = 'decasa-borradores'
const DB_VERSION = 1

let dbPromesa = null

function abrir() {
  if (dbPromesa) return dbPromesa
  dbPromesa = new Promise((resolve, reject) => {
    if (typeof indexedDB === 'undefined') { reject(new Error('Sin IndexedDB')); return }
    const req = indexedDB.open(DB_NOMBRE, DB_VERSION)
    req.onupgradeneeded = () => {
      const db = req.result
      if (!db.objectStoreNames.contains('borradores')) db.createObjectStore('borradores')
      if (!db.objectStoreNames.contains('fotos'))      db.createObjectStore('fotos')
    }
    req.onsuccess = () => {
      const db = req.result
      // Si otra pestaña abre una versión nueva, soltar esta para no bloquearla.
      db.onversionchange = () => { db.close(); dbPromesa = null }
      resolve(db)
    }
    req.onerror = () => reject(req.error)
    req.onblocked = () => reject(new Error('IndexedDB bloqueada'))
  })
  // Si falla, el próximo intento vuelve a probar en vez de heredar el error.
  dbPromesa.catch(() => { dbPromesa = null })
  return dbPromesa
}

function rangoFotos(clave) {
  return IDBKeyRange.bound(`${clave}|`, `${clave}|￿`)
}

function fin(tx) {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve()
    tx.onerror    = () => reject(tx.error)
    tx.onabort    = () => reject(tx.error ?? new Error('Transacción abortada'))
  })
}

/**
 * Guarda el borrador y sus fotos en una sola transacción: o queda todo, o no
 * queda nada (nunca un formulario que apunta a fotos que no están).
 *
 * @param {string} clave
 * @param {object} registro          Datos planos (con referencias { __foto }).
 * @param {Map<string, Blob>} fotosNuevas  Solo las que aún no están guardadas.
 * @param {Set<string>} fotosVivas   Todas las que el formulario usa ahora; las
 *                                   demás de esta clave se borran.
 */
export async function guardarBorrador(clave, registro, fotosNuevas, fotosVivas) {
  const db = await abrir()
  const tx = db.transaction(['borradores', 'fotos'], 'readwrite')
  const fotos = tx.objectStore('fotos')

  tx.objectStore('borradores').put(registro, clave)
  for (const [id, blob] of fotosNuevas) fotos.put(blob, `${clave}|${id}`)

  // Las que se quitaron del formulario no tienen por qué seguir ocupando espacio.
  const req = fotos.getAllKeys(rangoFotos(clave))
  req.onsuccess = () => {
    for (const llave of req.result) {
      const id = String(llave).slice(clave.length + 1)
      if (!fotosVivas.has(id)) fotos.delete(llave)
    }
  }

  await fin(tx)
}

/**
 * @returns {Promise<{ registro: object, fotos: Map<string, Blob> } | null>}
 */
export async function leerBorrador(clave) {
  const db = await abrir()
  const tx = db.transaction(['borradores', 'fotos'], 'readonly')
  const reqReg   = tx.objectStore('borradores').get(clave)
  const reqKeys  = tx.objectStore('fotos').getAllKeys(rangoFotos(clave))
  const reqFotos = tx.objectStore('fotos').getAll(rangoFotos(clave))
  await fin(tx)

  if (!reqReg.result) return null
  const fotos = new Map()
  reqKeys.result.forEach((llave, i) => {
    fotos.set(String(llave).slice(clave.length + 1), reqFotos.result[i])
  })
  return { registro: reqReg.result, fotos }
}

export async function borrarBorrador(clave) {
  const db = await abrir()
  const tx = db.transaction(['borradores', 'fotos'], 'readwrite')
  tx.objectStore('borradores').delete(clave)
  tx.objectStore('fotos').delete(rangoFotos(clave))
  await fin(tx)
}
