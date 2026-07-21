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
