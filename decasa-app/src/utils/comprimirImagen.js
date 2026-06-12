/**
 * Comprime una imagen usando Canvas antes de subirla.
 * - Redimensiona si supera MAX_DIM en cualquier lado.
 * - Convierte a JPEG con calidad QUALITY.
 * - Si el archivo ya es pequeño (< SKIP_BYTES) lo devuelve sin cambios.
 *
 * @param {File|Blob} archivo
 * @param {object}    opts
 * @param {number}    opts.maxDim   px máximos por lado (default 1920)
 * @param {number}    opts.quality  calidad JPEG 0-1 (default 0.82)
 * @param {number}    opts.skipBytes tamaño en bytes por debajo del cual no comprime (default 1 MB)
 * @returns {Promise<Blob>}
 */
export async function comprimirImagen(archivo, { maxDim = 1920, quality = 0.82, skipBytes = 1_048_576 } = {}) {
  if (archivo.size <= skipBytes) return archivo

  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(archivo)
    const img  = new Image()

    img.onload = () => {
      URL.revokeObjectURL(url)

      let { width, height } = img
      if (width > maxDim || height > maxDim) {
        const ratio = Math.min(maxDim / width, maxDim / height)
        width  = Math.round(width  * ratio)
        height = Math.round(height * ratio)
      }

      const canvas = document.createElement('canvas')
      canvas.width  = width
      canvas.height = height
      canvas.getContext('2d').drawImage(img, 0, 0, width, height)

      canvas.toBlob(
        blob => blob ? resolve(blob) : reject(new Error('No se pudo comprimir la imagen')),
        'image/jpeg',
        quality,
      )
    }

    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('No se pudo leer la imagen')) }
    img.src = url
  })
}
