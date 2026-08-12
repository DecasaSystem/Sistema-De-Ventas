/**
 * Pesos colombianos: punto para los miles y nada de decimales.
 *
 *   100 -> "100"       1000 -> "1.000"       10000 -> "10.000"
 *
 * Los centavos no existen en la práctica: nadie cobra $10.000,50, y arrastrar
 * ",00" en cada cifra sólo estorba al leer.
 */

/** Deja únicamente los dígitos de un texto. */
export function soloDigitos(texto) {
  return String(texto ?? '').replace(/\D/g, '')
}

/**
 * Cuánto vale algo que puede venir de dos sitios muy distintos.
 *
 * El backend manda los decimales como texto en formato inglés: "500000.00".
 * Una persona escribe en formato colombiano: "500.000". Los dos son cadenas
 * con un punto, y quedarse con los dígitos de la primera daba 50.000.000:
 * cien veces más. Así salían el anticipo, el precio de un producto de
 * catálogo y todo lo que llegara con centavos.
 *
 * Lo que los distingue es cuántos dígitos van tras el separador. En pesos el
 * separador de miles agrupa SIEMPRE de tres en tres, así que uno o dos
 * dígitos detrás sólo pueden ser centavos.
 */
function aNumero(valor) {
  if (typeof valor === 'number') return valor

  const texto = String(valor ?? '').trim()
  if (texto === '') return NaN

  // "500000.00", "50.00", "1580000.00" — como lo manda el backend.
  if (/^-?\d+(?:\.\d{1,2})?$/.test(texto)) return Number(texto)

  // "1.234.567" o "1.234.567,89" — como lo escribe o lo pega una persona.
  const n = Number(soloDigitos(quitarCentavos(texto)))
  return texto.startsWith('-') ? -n : n
}

/** 1234567 → "1.234.567". Redondea: no hay medios pesos. */
export function formatearPesos(valor) {
  if (valor === null || valor === undefined || valor === '') return ''
  const n = Math.round(aNumero(valor))
  if (!Number.isFinite(n)) return ''
  return new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(n)
}

/** Igual, con el signo delante: "$ 1.234.567". */
export function pesos(valor) {
  return '$ ' + formatearPesos(valor ?? 0)
}

/** "1.234.567" → 1234567. Lo que se escribe vuelve a ser número. */
export function pesosANumero(texto) {
  if (texto === null || texto === undefined || String(texto).trim() === '') return null
  const n = aNumero(texto)
  return Number.isFinite(n) ? Math.round(n) : null
}

/**
 * Quita los centavos de algo pegado desde fuera.
 *
 * Pegar "10000,50" y quedarse con todos los dígitos daría 1.000.050: cien
 * veces más de lo que la persona quería. Si el texto termina en separador más
 * uno o dos dígitos, eso son centavos y sobran. Tres dígitos detrás son miles
 * ("1.000"), y esos se respetan.
 *
 * Sólo se aplica a lo pegado: tecleando dígito a dígito nunca aparece un
 * separador que no haya puesto este mismo código.
 */
export function quitarCentavos(texto) {
  return String(texto ?? '').replace(/[.,]\d{1,2}$/, '')
}

/**
 * Dónde dejar el cursor tras reformatear.
 *
 * Al escribir, el texto cambia de longitud sola —"999" pasa a "9.999" al
 * teclear un dígito— así que la posición vieja ya no sirve. Lo único estable
 * es cuántos dígitos quedaban a la izquierda del cursor: se cuentan antes y
 * se busca esa misma posición en el texto nuevo.
 */
export function posicionTrasDigitos(texto, cuantosDigitos) {
  if (cuantosDigitos <= 0) return 0
  let vistos = 0
  for (let i = 0; i < texto.length; i++) {
    if (texto[i] >= '0' && texto[i] <= '9') {
      vistos++
      if (vistos === cuantosDigitos) return i + 1
    }
  }
  return texto.length
}
