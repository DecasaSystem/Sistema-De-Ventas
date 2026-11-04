/**
 * ¿El enlace es un PDF? Los PDF (comprobantes, anexos escaneados) se suben a
 * Cloudinary como "raw" con ".pdf" en el nombre (UploadController). No se
 * pintan con <img> ni van al visor de fotos: se abren en otra pestaña.
 */
export function esUrlDePdf(url) {
  if (!url || typeof url !== 'string') return false
  const limpio = url.split('?')[0].split('#')[0].toLowerCase()
  return limpio.endsWith('.pdf') || limpio.includes('/raw/upload/')
}

/**
 * Inserta transformaciones en un URL de Cloudinary para cargar imágenes optimizadas.
 * Si no es un URL de Cloudinary, lo devuelve tal cual.
 *
 * @param {string} url   - URL original de Cloudinary
 * @param {number} width - Ancho máximo en píxeles (0 = sin redimensionar)
 * @param {object} [opts]
 * @param {string} [opts.calidad] - 'best' para lo que el cliente mira de cerca
 *   (las hojas del catálogo, con letra pequeña): q_auto a secas comprime tanto
 *   que el texto y los bordes de los muebles se ven borrosos.
 */
export function cloudinaryOpt(url, width = 0, { calidad } = {}) {
  if (!url || !url.includes('res.cloudinary.com')) return url
  // Un PDF (subido como "raw") no admite transformaciones: el enlace tal cual.
  if (esUrlDePdf(url)) return url

  const transforms = ['f_auto', calidad ? `q_auto:${calidad}` : 'q_auto']
  if (width > 0) transforms.push(`w_${width}`, 'c_limit')

  return url.replace('/upload/', `/upload/${transforms.join(',')}/`)
}
