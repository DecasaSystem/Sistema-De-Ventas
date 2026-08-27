/**
 * Cuánto se demoró un paso del taller.
 *
 * El tiempo se guarda siempre en horas: es lo que se compara entre un
 * trabajador y otro y lo que multiplica la tarifa. Pero en el taller casi
 * nadie piensa en horas — un mueble "se demoró tres días" — y obligar a
 * traducirlo a mano era lo que hacía que se escribiera cualquier número. Por
 * eso el formulario deja escribir en días y aquí se pasa a horas.
 *
 * El día vale 8 horas para todos, a propósito. La jornada de nómina
 * (`horas_dia` del sueldo) es otra cosa: sirve para pagar, no para medir. Si
 * el día de uno valiera 9 y el de otro 8, "cuántas horas lleva cada quien"
 * dejaría de ser comparable, que es justo para lo que existe el dato.
 */
export const HORAS_POR_DIA = 8

/** Sin decimales de adorno: 3 y no "3.00", pero 2.5 sigue siendo 2.5. */
function limpio(n) {
  return Number(Number(n).toFixed(2))
}

/** Lo escrito en el formulario, pasado a horas. */
export function aHoras(valor, unidad = 'hora') {
  if (valor === '' || valor === null || valor === undefined) return null
  const n = Number(valor)
  if (!Number.isFinite(n)) return null
  return limpio(unidad === 'dia' ? n * HORAS_POR_DIA : n)
}

/** Las horas guardadas, mostradas en la unidad que se esté usando. */
export function enUnidad(horas, unidad = 'hora') {
  if (horas === null || horas === undefined || horas === '') return ''
  const n = Number(horas)
  if (!Number.isFinite(n)) return ''
  return limpio(unidad === 'dia' ? n / HORAS_POR_DIA : n)
}

/**
 * En qué unidad conviene abrir el campo de alguien que ya tiene horas puestas.
 *
 * Si son días redondos se abre en días: quien escribió "3 días" espera volver
 * a ver 3, no 24. Cualquier otra cosa se queda en horas, que es como está
 * guardada y no se presta a confusión.
 */
export function unidadSugerida(horas) {
  const n = Number(horas)
  if (!Number.isFinite(n) || n < HORAS_POR_DIA) return 'hora'
  return n % HORAS_POR_DIA === 0 ? 'dia' : 'hora'
}

/** Horas guardadas → "6 h", "3 d", "3 d 2 h". */
export function formatoDuracion(horas) {
  if (horas === null || horas === undefined || horas === '') return ''
  const n = Number(horas)
  if (!Number.isFinite(n)) return ''
  if (n < HORAS_POR_DIA) return `${limpio(n)} h`

  const dias  = Math.floor(n / HORAS_POR_DIA)
  const resto = limpio(n - dias * HORAS_POR_DIA)
  return resto ? `${dias} d ${resto} h` : `${dias} d`
}
