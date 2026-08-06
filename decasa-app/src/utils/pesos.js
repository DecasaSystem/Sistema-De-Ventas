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

/** 1234567 → "1.234.567". Redondea: no hay medios pesos. */
export function formatearPesos(valor) {
  if (valor === null || valor === undefined || valor === '') return ''
  const n = typeof valor === 'string' ? Number(soloDigitos(valor)) : Math.round(Number(valor))
  if (!Number.isFinite(n)) return ''
  return new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(n)
}

/** Igual, con el signo delante: "$ 1.234.567". */
export function pesos(valor) {
  return '$ ' + formatearPesos(valor ?? 0)
}

/** "1.234.567" → 1234567. Lo que se escribe vuelve a ser número. */
export function pesosANumero(texto) {
  const d = soloDigitos(texto)
  return d === '' ? null : Number(d)
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
