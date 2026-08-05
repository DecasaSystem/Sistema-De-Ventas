import * as XLSX from 'xlsx'

/**
 * Genera y descarga un archivo .xlsx a partir de un arreglo de objetos.
 * Cada objeto es una fila; las claves del objeto son los encabezados de
 * columna. Añade automáticamente la fecha del día al nombre del archivo.
 *
 * @param {Array<Object>} filas             Filas a exportar.
 * @param {Object}   opts
 * @param {string}   opts.nombreArchivo     Nombre base del archivo (sin extensión ni fecha).
 * @param {string}  [opts.hoja='Datos']     Nombre de la hoja.
 * @param {number[]} [opts.anchos]          Anchos de columna (wch). Si se omite, se autocalculan.
 */
export function exportarExcel(filas, { nombreArchivo, hoja = 'Datos', anchos } = {}) {
  const datos = (filas && filas.length) ? filas : [{ '(sin datos)': '' }]

  const worksheet = XLSX.utils.json_to_sheet(datos)
  const libro     = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(libro, worksheet, hoja.slice(0, 31))

  worksheet['!cols'] = anchos ?? autoAnchos(datos)

  const fecha = new Date().toISOString().slice(0, 10)
  XLSX.writeFile(libro, `${nombreArchivo}_${fecha}.xlsx`)
}

/**
 * Igual que exportarExcel, pero reparte los datos en varias hojas.
 *
 * @param {Array<{nombre: string, filas: Array<Object>, anchos?: number[]}>} hojas
 * @param {Object} opts
 * @param {string} opts.nombreArchivo   Nombre base (sin extensión ni fecha).
 */
export function exportarExcelHojas(hojas, { nombreArchivo } = {}) {
  const libro  = XLSX.utils.book_new()
  const usados = new Set()

  for (const { nombre, filas, anchos } of hojas) {
    const datos = (filas && filas.length) ? filas : [{ '(sin datos)': '' }]
    const hoja  = XLSX.utils.json_to_sheet(datos)
    hoja['!cols'] = anchos ?? autoAnchos(datos)
    XLSX.utils.book_append_sheet(libro, hoja, nombreHojaValido(nombre, usados))
  }

  const fecha = new Date().toISOString().slice(0, 10)
  XLSX.writeFile(libro, `${nombreArchivo}_${fecha}.xlsx`)
}

/**
 * Excel no abre el archivo —falla entero, no la hoja— si un nombre de hoja
 * pasa de 31 caracteres, va vacío, se repite o trae : \ / ? * [ ]. Los nombres
 * salen de las categorías, que las escribe gente, así que se saneen aquí.
 */
function nombreHojaValido(nombre, usados) {
  const base = String(nombre ?? '')
    .replace(/[:\\/?*[\]]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 31) || 'Hoja'

  let final = base
  let n = 2
  while (usados.has(final.toLowerCase())) {
    const sufijo = ` (${n++})`
    final = base.slice(0, 31 - sufijo.length) + sufijo
  }
  usados.add(final.toLowerCase())
  return final
}

/** Calcula el ancho de cada columna según el contenido más largo. */
function autoAnchos(filas) {
  const claves = Object.keys(filas[0] ?? {})
  return claves.map(clave => {
    const maxLen = filas.reduce((max, fila) => {
      const val = fila[clave] == null ? '' : String(fila[clave])
      return Math.max(max, val.length)
    }, clave.length)
    return { wch: Math.min(Math.max(maxLen + 2, 10), 50) }
  })
}
