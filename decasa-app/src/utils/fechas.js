/**
 * Fechas de calendario en hora de Colombia.
 *
 * `new Date().toISOString().slice(0, 10)` da el día en UTC: de 7 p. m. a
 * medianoche ya es "mañana". Así, en la noche, Nueva orden no dejaba escoger
 * hoy como fecha de entrega, un daño quedaba con fecha de mañana, Despacho
 * proponía mañana para la ruta y Bonificaciones abría el mes siguiente el
 * último día del mes (revisado 2026-11-14). Para un día o un mes del
 * negocio, usar esto.
 */
const TZ = 'America/Bogota'

/** 'Y-m-d' de un momento (por defecto, ahora) en Colombia. */
export function fechaBogota(momento = new Date()) {
  // en-CA escribe las fechas como 2026-11-14.
  return new Date(momento).toLocaleDateString('en-CA', { timeZone: TZ })
}

/** Hoy en Colombia, 'Y-m-d'. */
export const hoyBogota = () => fechaBogota()

/** El mes en curso en Colombia, 'Y-m'. */
export const mesBogota = () => hoyBogota().slice(0, 7)

/** 'Y-m-dTH:i' en Colombia, para un `<input type="datetime-local">`. */
export function fechaHoraBogota(momento = new Date()) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(momento)).map(x => [x.type, x.value]))
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`
}
