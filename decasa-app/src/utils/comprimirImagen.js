/**
 * Comprime una imagen usando Canvas antes de subirla.
 * - Redimensiona si supera MAX_DIM en cualquier lado.
 * - Convierte a JPEG con calidad QUALITY.
 * - Si el archivo ya es pequeño (< SKIP_BYTES) lo devuelve sin cambios.
 *
 * NUNCA rechaza: si el navegador no puede decodificar la foto (una de cámara
 * muy grande en un celular con poca memoria, un formato que no entiende), se
 * devuelve el archivo original tal cual. Comprimir es un ahorro de datos, no
 * un requisito; antes un fallo aquí tumbaba la creación de la orden entera
 * sin que saliera ni una petición al servidor, y como en el computador se
 * adjuntan pantallazos pequeños que ni pasan por aquí, solo fallaba desde el
 * teléfono.
 *
 * @param {File|Blob} archivo
 * @param {object}    opts
 * @param {number}    opts.maxDim   px máximos por lado (default 1920)
 * @param {number}    opts.quality  calidad JPEG 0-1 (default 0.82)
 * @param {number}    opts.skipBytes tamaño en bytes por debajo del cual no comprime (default 1 MB)
 * @returns {Promise<Blob>}
 */
export async function comprimirImagen(archivo, { maxDim = 1920, quality = 0.82, skipBytes = 1_048_576 } = {}) {
  if (!archivo || archivo.size <= skipBytes) return archivo

  try {
    const fuente = await decodificar(archivo)
    let { width, height } = fuente
    if (!width || !height) throw new Error('Imagen sin dimensiones')

    if (width > maxDim || height > maxDim) {
      const ratio = Math.min(maxDim / width, maxDim / height)
      width  = Math.round(width  * ratio)
      height = Math.round(height * ratio)
    }

    const canvas = document.createElement('canvas')
    canvas.width  = width
    canvas.height = height
    canvas.getContext('2d').drawImage(fuente, 0, 0, width, height)
    if (typeof fuente.close === 'function') fuente.close()

    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality))
    if (!blob) throw new Error('toBlob devolvió null')

    // Si "comprimida" quedó más pesada que la original, no valió la pena.
    return blob.size < archivo.size ? blob : archivo
  } catch (e) {
    console.warn('[comprimirImagen] No se pudo comprimir, se sube la original:', e?.message ?? e)
    return archivo
  }
}

/**
 * Decodifica la foto a algo que se pueda dibujar en un canvas.
 *
 * `createImageBitmap` va primero: respeta la orientación EXIF de la cámara
 * (una foto vertical no sale acostada) y gasta menos memoria que <img> en
 * fotos grandes. Si el navegador no lo tiene, o falla, se intenta con <img>.
 */
async function decodificar(archivo) {
  if (typeof createImageBitmap === 'function') {
    try {
      return await createImageBitmap(archivo, { imageOrientation: 'from-image' })
    } catch {
      // Algunos navegadores no aceptan la opción o el formato: se cae a <img>.
      try { return await createImageBitmap(archivo) } catch {}
    }
  }

  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(archivo)
    const img = new Image()
    img.onload  = () => { URL.revokeObjectURL(url); resolve(img) }
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('No se pudo leer la imagen')) }
    img.src = url
  })
}
