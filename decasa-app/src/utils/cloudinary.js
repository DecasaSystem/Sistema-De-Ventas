/**
 * Inserta transformaciones en un URL de Cloudinary para cargar imágenes optimizadas.
 * Si no es un URL de Cloudinary, lo devuelve tal cual.
 *
 * @param {string} url   - URL original de Cloudinary
 * @param {number} width - Ancho máximo en píxeles (0 = sin redimensionar)
 */
export function cloudinaryOpt(url, width = 0) {
  if (!url || !url.includes('res.cloudinary.com')) return url

  const transforms = ['f_auto', 'q_auto']
  if (width > 0) transforms.push(`w_${width}`, 'c_limit')

  return url.replace('/upload/', `/upload/${transforms.join(',')}/`)
}
