/**
 * Los datos del cliente mal escritos, revisados mientras se escriben (Nueva
 * orden), para no tener que corregirlos al facturar (usuario, 2026-11-13).
 *
 * Es la MISMA regla que App\Services\Facturacion\RevisarCliente en el
 * servidor (la que revisa al facturar): si se cambia una, se cambia la otra.
 * Caso que lo originó: la #4421, "INVERSIONES CONVERSA SAS" guardada como
 * cédula con 9006232083 = NIT 900623208 con el dígito de verificación (3) pegado.
 *
 * Cada aviso: { tipo: 'bloqueo'|'advertencia'|'info', codigo, mensaje,
 * arreglo: null | { texto, campos } }. Aquí nada frena la venta: solo avisa.
 */

const DOMINIOS = {
  'gmail.con': 'gmail.com', 'gmail.co': 'gmail.com', 'gmial.com': 'gmail.com', 'gmal.com': 'gmail.com',
  'gmai.com': 'gmail.com', 'gamil.com': 'gmail.com', 'gnail.com': 'gmail.com', 'gmail.cm': 'gmail.com',
  'hotmail.con': 'hotmail.com', 'hotmal.com': 'hotmail.com', 'hotmial.com': 'hotmail.com', 'hotmail.co': 'hotmail.com',
  'hotmai.com': 'hotmail.com', 'outlook.con': 'outlook.com', 'outlok.com': 'outlook.com', 'outlook.co': 'outlook.com',
  'yahoo.con': 'yahoo.com', 'yaho.com': 'yahoo.com', 'icloud.con': 'icloud.com',
}

/** Dígito de verificación del NIT (algoritmo de la DIAN, módulo 11). */
export function digitoVerificacion(nit) {
  const pesos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71]
  const d = String(nit).replace(/\D/g, '').split('').reverse()
  const suma = d.reduce((s, x, i) => s + Number(x) * (pesos[i] ?? 0), 0)
  const r = suma % 11
  return r > 1 ? 11 - r : r
}

/** SAS, LTDA, S.A., E.U., cooperativa, fundación… (sin tildes, sin puntos, en mayúscula). */
export function pareceEmpresa(nombre) {
  const n = String(nombre || '').normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toUpperCase().replace(/[.,]/g, '').replace(/\s+/g, ' ').trim()
  return /\b(S ?A ?S|LTDA|LIMITADA|S EN C|S ?C ?A|COOPERATIVA|FUNDACION|ASOCIACION|CORPORACION|CONSORCIO|UNION TEMPORAL|SOCIEDAD)\b/.test(n)
    || /\b(S ?A|E ?U|E ?S ?P|S ?A ?S BIC)$/.test(n)
}

/** Si es un NIT de empresa: [nit sin dv, dv]. Empiezan por 8 o 9; con 10 cifras que cuadran, la última es el dv pegado. */
export function nitDeEmpresa(doc) {
  doc = String(doc || '')
  if (!/^[89]\d+$/.test(doc)) return null
  if (doc.length === 9) return [doc, String(digitoVerificacion(doc))]
  if (doc.length === 10) {
    const base = doc.slice(0, 9)
    if (String(digitoVerificacion(base)) === doc.slice(-1)) return [base, doc.slice(-1)]
  }
  return null
}

const NOMBRE_TIPO = { CC: 'cédula', CE: 'cédula de extranjería', TI: 'tarjeta de identidad', RC: 'registro civil', PP: 'pasaporte' }

/**
 * @param c { nombre, cedula, tipo_documento, dv, tipo_persona, email, telefono }
 */
export function revisarCliente(c) {
  const out = []
  const nombre  = String(c?.nombre || '').trim()
  const doc     = String(c?.cedula || '').replace(/[^0-9A-Za-z]/g, '')
  const tipo    = c?.tipo_documento || (doc ? 'CC' : '')
  const dv      = String(c?.dv ?? '').trim()
  const persona = c?.tipo_persona || (tipo === 'NIT' ? 'juridica' : 'natural')
  const nit     = nitDeEmpresa(doc)
  const pegado  = nit && nit[0] !== doc

  if (nombre && pareceEmpresa(nombre) && (tipo !== 'NIT' || persona !== 'juridica')) {
    const campos = { tipo_documento: 'NIT', tipo_persona: 'juridica', ...(nit ? { cedula: nit[0], dv: nit[1] } : {}) }
    out.push({
      tipo: 'advertencia', codigo: 'cliente_empresa',
      mensaje: `"${nombre}" es una empresa: va con NIT y como persona jurídica`
        + (pegado ? `. El número ${doc} parece el NIT ${nit[0]} con el dígito de verificación ${nit[1]} pegado.` : '.'),
      arreglo: { texto: nit ? `NIT ${nit[0]}-${nit[1]}, empresa` : 'Es empresa (NIT)', campos },
    })
  } else if (tipo === 'CC' && pegado) {
    out.push({
      tipo: 'advertencia', codigo: 'cedula_parece_nit',
      mensaje: `${doc} no parece una cédula: parece el NIT ${nit[0]} de una empresa, con el dígito ${nit[1]} pegado.`,
      arreglo: { texto: `NIT ${nit[0]}-${nit[1]}, empresa`, campos: { tipo_documento: 'NIT', tipo_persona: 'juridica', cedula: nit[0], dv: nit[1] } },
    })
  } else if (tipo === 'NIT' && pegado) {
    out.push({
      tipo: 'advertencia', codigo: 'nit_con_dv',
      mensaje: `El NIT ${doc} parece traer el dígito de verificación pegado: sería ${nit[0]}-${nit[1]}.`,
      arreglo: { texto: `NIT ${nit[0]}-${nit[1]}`, campos: { cedula: nit[0], dv: nit[1] } },
    })
  }

  if (tipo === 'NIT' && /^\d+$/.test(doc) && dv !== '' && !pegado) {
    const bueno = String(digitoVerificacion(doc))
    if (dv !== bueno) {
      out.push({ tipo: 'bloqueo', codigo: 'dv_malo', mensaje: `El dígito de verificación del NIT ${doc} es ${bueno}, no ${dv}.`,
        arreglo: { texto: `Dígito ${bueno}`, campos: { dv: bueno } } })
    }
  }

  if (persona === 'juridica' && ['CC', 'TI', 'RC'].includes(tipo)) {
    out.push({ tipo: 'bloqueo', codigo: 'juridica_con_cedula', mensaje: `Una empresa no tiene ${NOMBRE_TIPO[tipo]}: se identifica con NIT.`,
      arreglo: { texto: nit ? `NIT ${nit[0]}-${nit[1]}` : 'Cambiar a NIT', campos: { tipo_documento: 'NIT', ...(nit ? { cedula: nit[0], dv: nit[1] } : {}) } } })
  }

  if (tipo === 'CC' && doc) {
    if (!/^\d+$/.test(doc)) {
      out.push({ tipo: 'bloqueo', codigo: 'cedula_con_letras', mensaje: `La cédula "${doc}" tiene letras: es solo números.`, arreglo: null })
    } else if (doc.length < 5 || doc.length > 10) {
      out.push({ tipo: 'advertencia', codigo: 'cedula_rara', mensaje: `La cédula ${doc} tiene ${doc.length} cifras: las colombianas tienen entre 5 y 10.`, arreglo: null })
    }
  }

  const email = String(c?.email || '').trim()
  if (email) {
    const dominio = (email.split('@')[1] || '').toLowerCase()
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
      out.push({ tipo: 'bloqueo', codigo: 'correo_malo', mensaje: `El correo "${email}" no está bien escrito (le falta algo como ".com").`, arreglo: null })
    } else if (DOMINIOS[dominio]) {
      const bueno = email.slice(0, -dominio.length) + DOMINIOS[dominio]
      out.push({ tipo: 'advertencia', codigo: 'correo_dudoso', mensaje: `¿No será ${bueno}? Si el correo está mal, no le llega la factura.`,
        arreglo: { texto: `Usar ${bueno}`, campos: { email: bueno } } })
    }
  }

  const tel = String(c?.telefono || '').trim()
  if (tel && /[A-Za-zÁÉÍÓÚáéíóúÑñ]/.test(tel)) {
    const m = tel.match(/\+?\d[\d\s-]{6,}\d/)
    const numero = m ? m[0].replace(/[\s-]/g, '') : null
    out.push({ tipo: 'info', codigo: 'telefono_con_texto', mensaje: `El teléfono dice "${tel}": va solo el número (el nombre del contacto va en notas).`,
      arreglo: numero ? { texto: `Dejar ${numero}`, campos: { telefono: numero } } : null })
  }

  return out
}
