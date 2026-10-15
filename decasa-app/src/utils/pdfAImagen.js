/**
 * Convierte un PDF (el que ya arma el servidor) en una imagen para mandar por
 * WhatsApp. Los asesores no mandan PDFs a los grupos de comprobantes: mandan
 * fotos. Antes tomaban captura de pantalla del PDF abierto y salía cortada o
 * borrosa.
 *
 * Se dibuja en el celular con pdf.js, no en el servidor: así la imagen es
 * exactamente la misma hoja del PDF (una sola fuente de verdad) y Render no
 * carga con Imagick/Ghostscript.
 *
 * pdf.js pesa: se carga solo la primera vez que alguien pide una imagen.
 * Se usa la versión "legacy" porque hay celulares de vendedores con Chrome
 * viejo y la normal usa funciones que esos no tienen.
 */

let pdfjsPromesa = null

function cargarPdfjs() {
  if (!pdfjsPromesa) {
    pdfjsPromesa = Promise.all([
      import('pdfjs-dist/legacy/build/pdf.min.mjs'),
      import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url'),
    ]).then(([pdfjs, worker]) => {
      pdfjs.GlobalWorkerOptions.workerSrc = worker.default
      return pdfjs
    }).catch((e) => {
      // Si falló (sin señal), que el siguiente intento vuelva a cargarlo.
      pdfjsPromesa = null
      throw e
    })
  }
  return pdfjsPromesa
}

// Ancho de la imagen en píxeles. WhatsApp la recomprime a ~1600 px: más que
// eso pesa sin verse mejor; menos y la letra pequeña de la orden no se lee.
const ANCHO = 1600
// Lo que queda de blanco debajo de lo último escrito.
const MARGEN_ABAJO = 48

/**
 * @param {Blob} pdfBlob
 * @returns {Promise<Blob>} JPEG con todas las páginas, una debajo de otra.
 */
export async function pdfAImagen(pdfBlob) {
  const pdfjs = await cargarPdfjs()
  const datos = new Uint8Array(await pdfBlob.arrayBuffer())
  const carga = pdfjs.getDocument({ data: datos })
  const doc = await carga.promise

  try {
    const paginas = []
    for (let n = 1; n <= doc.numPages; n++) {
      const pagina = await doc.getPage(n)
      const base = pagina.getViewport({ scale: 1 })
      const viewport = pagina.getViewport({ scale: ANCHO / base.width })
      const canvas = document.createElement('canvas')
      canvas.width = Math.round(viewport.width)
      canvas.height = Math.round(viewport.height)
      // Fondo blanco: el PDF es transparente y el JPEG lo volvería negro.
      await pagina.render({ canvas, viewport, background: '#ffffff' }).promise
      paginas.push(recortarBlancoAbajo(canvas))
    }

    // La orden sale en una sola hoja (PdfOrdenUnaHoja), pero si algún día
    // salen dos, van en la misma imagen: en el grupo es un solo comprobante.
    const final = document.createElement('canvas')
    final.width = ANCHO
    final.height = paginas.reduce((s, p) => s + p.alto, 0)
    const ctx = final.getContext('2d')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, final.width, final.height)
    let y = 0
    for (const p of paginas) {
      ctx.drawImage(p.canvas, 0, 0, p.canvas.width, p.alto, 0, y, p.canvas.width, p.alto)
      y += p.alto
    }

    return await new Promise((resolve, reject) => {
      final.toBlob(b => (b ? resolve(b) : reject(new Error('No se pudo crear la imagen'))), 'image/jpeg', 0.9)
    })
  } finally {
    // Suelta el worker y la memoria del documento (la orden puede tener firmas).
    carga.destroy()
  }
}

/**
 * La hoja es tamaño carta y la orden casi nunca la llena: en el chat se veía
 * media imagen en blanco. Se corta lo que sobra abajo.
 */
function recortarBlancoAbajo(canvas) {
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  const { width, height } = canvas
  const pixeles = ctx.getImageData(0, 0, width, height).data
  let ultima = 0
  for (let fila = height - 1; fila >= 0 && !ultima; fila--) {
    const inicio = fila * width * 4
    for (let i = inicio; i < inicio + width * 4; i += 4) {
      // "Casi blanco" y no blanco exacto: el antialias deja grises de 250+.
      if (pixeles[i] < 245 || pixeles[i + 1] < 245 || pixeles[i + 2] < 245) {
        ultima = fila
        break
      }
    }
  }
  const alto = ultima ? Math.min(height, ultima + MARGEN_ABAJO) : height
  return { canvas, alto }
}

/**
 * ¿Este aparato puede mandar el archivo al menú de compartir (WhatsApp → el
 * grupo, sin pasar por la galería)? En el computador casi nunca.
 */
export function sePuedeCompartir(blob, nombre) {
  try {
    return !!navigator.canShare?.({ files: [new File([blob], nombre, { type: blob.type })] })
  } catch {
    return false
  }
}

/**
 * Abre el menú de compartir. Tiene que llamarse directo desde un toque: el
 * navegador lo niega si pasaron segundos desde el último (y generar la imagen
 * tarda), por eso la pantalla muestra la imagen lista con su propio botón.
 *
 * @returns {Promise<boolean>} false si cerró el menú sin escoger.
 */
export async function compartirImagen(blob, nombre) {
  try {
    await navigator.share({ files: [new File([blob], nombre, { type: blob.type })] })
    return true
  } catch (e) {
    if (e?.name === 'AbortError') return false
    throw e
  }
}

export function descargarImagen(blob, nombre) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = nombre
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10000)
}
