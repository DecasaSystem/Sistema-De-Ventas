/**
 * Enlaces a WhatsApp desde un teléfono como lo escriben aquí.
 *
 * wa.me exige el número en formato internacional y sin signos. En la base los
 * teléfonos están casi siempre como "3001234567" o "300 123 4567", así que hay
 * que limpiarlos y ponerles el indicativo.
 */

const INDICATIVO_CO = '57'

/**
 * Normaliza un teléfono colombiano al formato que espera wa.me.
 * Devuelve null si no parece un número al que se le pueda escribir.
 */
export function telefonoWhatsapp(telefono) {
  if (!telefono) return null

  // Solo dígitos: se van espacios, guiones, paréntesis y el +
  let n = String(telefono).replace(/\D/g, '')
  if (!n) return null

  // Algunos vienen con ceros de marcación larga ("0057…", "009…")
  n = n.replace(/^0+/, '')

  // Ya trae indicativo: 57 + 10 dígitos
  if (n.length === 12 && n.startsWith(INDICATIVO_CO)) return n

  // Diez dígitos es un número colombiano completo: celular (3xx) o fijo con
  // indicativo de ciudad (60x). Le falta el país. Si se devolviera tal cual,
  // wa.me lo leería como de otro país y abriría un chat con quien no es.
  if (n.length === 10) return INDICATIVO_CO + n

  // Menos de 10 dígitos: fijo sin indicativo de ciudad, no sirve
  if (n.length < 10) return null

  // Más largo: ya viene con país (otro distinto de Colombia)
  return n.length <= 15 ? n : null
}

/** ¿A este teléfono se le puede escribir por WhatsApp? */
export function tieneWhatsapp(telefono) {
  return telefonoWhatsapp(telefono) !== null
}

/**
 * URL de WhatsApp con el mensaje ya escrito. Se abre en el móvil con la app y
 * en el computador con WhatsApp Web.
 */
export function urlWhatsapp(telefono, mensaje = '') {
  const n = telefonoWhatsapp(telefono)
  if (!n) return null
  const texto = mensaje ? `?text=${encodeURIComponent(mensaje)}` : ''
  return `https://wa.me/${n}${texto}`
}
