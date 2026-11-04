/**
 * De dónde sale lo que se paga en Comisiones → Listas, en palabras.
 *
 * Lo usan la pantalla de Listas (bajo cada mes de cada persona) y el Excel de
 * Listas, para que digan lo mismo. Antes solo el Excel lo explicaba; en la
 * pantalla salía "$58.991" y nadie sabía que era por los pocos días que
 * estuvo en la tienda (dueño, 2026-11-04, caso Génesis en Unicentro: "que en
 * Listas se llene de información relevante").
 *
 * No calcula plata: todo sale de GET /comisiones/por-pagar?detalle=1.
 */

import { pesos, n, fecha, mesEnPalabras } from './excelComisiones'
import { num, divisor, cuentaPool, cuentaVenta, laParte, partePagada } from './reglasComision'

/**
 * Las líneas que explican lo listo de un bloque (una persona, un mes, una tienda).
 *
 * @param {Object} p        la persona de la respuesta
 * @param {Object} b        su bloque de ese mes
 * @param {Object} r        las reglas de ese mes, tienda y persona
 * @param {Object} [tienda] la cuenta de la tienda en ese mes (respuesta.tiendas)
 * @param {Object} [opc]    { excel: true } nombra las hojas del libro
 */
export function explicacionDelBloque(p, b, r, tienda = null, { excel = false } = {}) {
  const c = b.cuenta ?? {}
  const ordenes = b.ordenes_listas ?? []
  const suma = formas => ordenes.filter(o => formas.includes(o.forma_pago)).reduce((s, o) => s + n(o.monto_comision), 0)
  const verHoja = excel ? ' Ver hoja "Por tienda".' : ''
  const lineas = []

  if (b.es_independiente) {
    const i = b.independiente ?? {}
    lineas.push(`Independiente: cobra el ${num(r.indep_pct_venta)}% fijo de sus ventas${r.iva_en_venta ? ` (÷ ${divisor(r.iva_divisor)})` : ''}, sin meta ni pool.`)
    lineas.push(`De sus ventas listas sin pagar: ${pesos(i.por_pagar_ventas)}. Del bolsón de restauraciones: ${pesos(i.por_pagar_bolson)}.`)
  } else {
    const delPool = suma(['pool', 'parte_pool'])
    if (c.por_pool && delPool > 0) {
      if (b.periodicidad === 'trimestral') {
        lineas.push(`${b.tienda_nombre} es trimestral (${c.trimestre ?? ''}): los tres meses se juntan en un solo pool: ${pesos(c.comision_pool)}.${verHoja}`)
        lineas.push(...trimestreTexto(b, r, tienda?.pool?.trimestre, c))
        lineas.push(`Su pedazo de ${mesEnPalabras(b.mes)}: ${c.parte_dias} días de ${c.partes_dias} días de todo el equipo en el trimestre = ${pesos(c.comision_asesor)}.`)
        if (n(c.partes_dias) > 0 && n(c.parte_dias) / n(c.partes_dias) < 0.2) {
          lineas.push(`Es poco porque en ${mesEnPalabras(b.mes)} estuvo pocos días en la tienda: el pool se parte por los días que trabajó cada uno.`)
        }
      } else {
        lineas.push(`Pool de ${b.tienda_nombre}: (${pesos(c.total_tienda_mes)} vendido con ${partePagada(r.meta_req_pagado_pct)} − ${pesos(c.meta_tienda)} de meta) ${cuentaPool(r)} = ${pesos(c.comision_pool)}.`)
        lineas.push(r.reparto_pool === 'iguales'
          ? `Su parte: partes iguales entre el equipo = ${pesos(c.comision_asesor)}.`
          : `Su parte: ${c.parte_dias} de ${c.partes_dias} días del equipo = ${pesos(c.comision_pool)} × ${c.parte_dias} ÷ ${c.partes_dias} = ${pesos(c.comision_asesor)}.`)
        if (tienda?.pool?.sin_mitad > 0) {
          lineas.push(`No cuentan todavía ${pesos(tienda.pool.sin_mitad)} de ventas de la tienda sin ${partePagada(r.meta_req_pagado_pct)}: cuando las paguen, el pool crece y sale la diferencia.`)
        }
      }
      lineas.push(...movimientosTexto(p, b))
      lineas.push(n(c.ya_pagado_pool) > 0
        ? `Ya se le pagó de este pool ${pesos(c.ya_pagado_pool)}: queda ${pesos(delPool)}.`
        : `Del pool se le paga ahora ${pesos(delPool)}.`)
    }
    const rest = suma(['restauracion_5', 'restauracion_equipo'])
    if (rest > 0) lineas.push(`Restauraciones: ${pesos(rest)} (${num(r.pct_restauracion)}% de su valor${r.iva_en_restauracion ? '' : ', sin quitarle el IVA'}${suma(['restauracion_equipo']) > 0 ? '; las de la tienda se parten entre los que estaban ese día' : ''}).`)
    const ind = suma(['sin_meta_5'])
    if (ind > 0) lineas.push(`Individual (no va por pool): ${pesos(ind)} = ventas ${cuentaVenta(r, 'pct_individual')}.`)
    const rem = suma(['reemplazo_ventas'])
    if (rem > 0) lineas.push(`Lo que vendió cubriendo a alguien: ${pesos(rem)} = ventas ${cuentaVenta(r, 'pct_reemplazo')} (no suma a la meta ni al pool).`)
    const ab = suma(['abono_almacen'])
    if (ab > 0) lineas.push(`Lo que dejaron ventas de independientes con la tienda: ${pesos(ab)}.`)
  }

  lineas.push(`Listo para pagar de ${mesEnPalabras(b.mes)}: ${pesos(b.lista)}. `
    + (b.atrasada ? `ATRASADA: se debía pagar el ${fecha(b.se_cobra_el)}.` : `Se paga el ${fecha(b.se_cobra_el)}.`))
  if (n(b.pendientes) > 0) {
    lineas.push(`${b.pendientes} orden(es) de este mes todavía no entran (el cliente no ha pagado ${laParte(r.req_pagado_pct)}): no están en este pago.`)
  }
  if (n(b.se_descuenta) > 0 || n(b.debe_al_corte) > 0) {
    lineas.push(`Anticipos: debía ${pesos(b.debe_al_corte)} a este corte`
      + (n(b.abonos) > 0 ? ` (incluye ${pesos(b.abonos)} de abonos de esta comisión ya entregados)` : '')
      + `. Se descuentan ${pesos(b.se_descuenta)} → se le entregan ${pesos(b.entregar)}.`)
  }
  return lineas
}

/**
 * Por qué un mes de una tienda trimestral cobra aunque ese mes no llegó a la
 * meta: se suman los tres. Juan Esteban (Unicentro, agosto 2026) veía "cobra
 * $346.245" al lado de una tabla donde agosto quedó por debajo, y nada decía
 * que julio y septiembre lo cubrieron (dueño, 2026-11-04).
 */
function trimestreTexto(b, r, q, c) {
  if (!q?.meses?.length) return []
  const mesCorto = m => mesEnPalabras(m).replace(/ de \d{4}$/, '')
  const firmado = v => (n(v) >= 0 ? '+' : '−') + pesos(Math.abs(n(v)))
  const lineas = []
  const este = q.meses.find(m => m.mes === b.mes)
  if (este && n(este.meta) > 0) {
    lineas.push(n(este.cuenta) < n(este.meta)
      ? `En ${mesCorto(b.mes)} la tienda no llegó a la meta (${pesos(este.cuenta)} de ${pesos(este.meta)}), pero en una tienda trimestral no se mira cada mes solo: se suman los tres.`
      : `En ${mesCorto(b.mes)} la tienda pasó la meta por ${pesos(n(este.cuenta) - n(este.meta))}; igual se mira el trimestre completo.`)
  }
  // El saldo ya arranca con la deuda del trimestre anterior (cuentaDelTrimestre).
  const saldo = n([...q.meses].reverse().find(m => m.saldo != null)?.saldo)
  const deuda = n(q.deuda_en_ventas)
  lineas.push(`Cada mes, lo que cuenta menos la meta: ${q.meses.map(m => `${mesCorto(m.mes)} ${firmado(m.diferencia)}`).join(', ')}`
    + (deuda > 0 ? `, menos ${pesos(deuda)} de deuda del trimestre anterior` : '')
    + `. El trimestre quedó ${firmado(saldo)}: ${pesos(saldo)} ${cuentaPool(r)} = pool de ${pesos(c.comision_pool)}.`)
  lineas.push('Ese pool se reparte por los días que estuvo cada uno en los tres meses: a cada mes le toca lo de sus días, aunque ese mes solo no haya llegado.')
  return lineas
}

/**
 * Los traslados y reemplazos de la persona en esa tienda (bloque.movimientos):
 * son los que explican sus días. Génesis: "llegó el 27/08/2026 por traslado
 * (Se pasó a Unicentro al cerrar Circunvalar)".
 */
export function movimientosTexto(p, b) {
  const rango = m => m.hasta ? `del ${fecha(m.desde)} al ${fecha(m.hasta)}` : `desde el ${fecha(m.desde)}`
  const nota = m => m.nota ? ` (${m.nota})` : ''
  return (b.movimientos ?? []).map(m => {
    if (m.tipo === 'traslado') {
      return `Estuvo en ${b.tienda_nombre} por traslado ${rango(m)}${nota(m)}: esos días son los que le cuentan aquí.`
    }
    return m.es_quien_cubre
      ? `Cubrió a ${m.reemplaza ?? 'alguien'} ${rango(m)}${nota(m)}: esos días le cuentan a ${p.vendedor_nombre}.`
      : `${m.quien} cubrió a ${p.vendedor_nombre} ${rango(m)}${nota(m)}: esos días son de ${m.quien}.`
  })
}

/**
 * Por qué una tienda no comisiona nada en este pago (respuesta.tiendas_sin_pago).
 * Es lo que hay que poder decirle al vendedor.
 */
export function porQueNoComisiona(t, r, mesQueToca = null) {
  const pool = t.pool ?? {}
  const lineas = []
  if (t.cerrada_en) {
    lineas.push(`La tienda cerró el ${fecha(t.cerrada_en)}. Lo que se vendió antes sí contó para su meta, pero no alcanzó.`)
  }
  if (pool.periodicidad === 'trimestral' && pool.trimestre) {
    const q = pool.trimestre
    // Cerrado si su último mes ya es el que se paga (o pasó). Circunvalar no
    // tiene ventas de septiembre (cerró el 27 de agosto): mirar solo el mes de
    // su cuenta lo daba por "en curso".
    const cierra = (q.meses ?? []).map(m => m.mes).sort().pop() ?? t.mes
    if (mesQueToca ? cierra > mesQueToca : cierra > t.mes) {
      lineas.push(`Es trimestral (${q.trimestre}): el pool se paga al cerrar el trimestre, no cada mes. Va así:`)
    } else {
      lineas.push(`Es trimestral (${q.trimestre}): sumando los tres meses no pasó de la meta, así que no hay pool.`)
    }
    if (n(q.deficit_inicial) > 0) {
      lineas.push(`Traía una deuda del trimestre anterior de ${pesos(q.deuda_en_ventas)} en ventas: también había que cubrirla.`)
    }
    if (n(q.falta_para_cobrar) > 0) lineas.push(`Le faltaron ${pesos(q.falta_para_cobrar)} en ventas que cuenten para tener pool.`)
    if (n(q.deficit_final) > 0) lineas.push(`Pasa al siguiente trimestre una deuda de ${pesos(q.deficit_final)} de pool.`)
    return lineas
  }
  const meta = n(pool.meta)
  const cuenta = n(pool.ventas_cuentan)
  if (meta > 0 && cuenta < meta) {
    lineas.push(`No llegó a la meta de ${mesEnPalabras(t.mes)}: con ${partePagada(r.meta_req_pagado_pct)} lleva ${pesos(cuenta)} de ${pesos(meta)} (le faltaron ${pesos(meta - cuenta)}). Sin meta no hay pool.`)
  } else if (meta > 0) {
    lineas.push(`Llegó a la meta, pero el pool ya se pagó o todavía no hay nada listo.`)
  }
  if (n(pool.sin_mitad) > 0) {
    lineas.push(`Hay ${pesos(pool.sin_mitad)} vendidos (${pool.ordenes_sin_mitad} orden${pool.ordenes_sin_mitad !== 1 ? 'es' : ''}) que no cuentan porque el cliente no ha pagado ${laParte(r.meta_req_pagado_pct)}. Si los pagan, ${meta > 0 && cuenta + n(pool.sin_mitad) >= meta ? 'llegaría a la meta' : 'se acerca a la meta'}.`)
  }
  return lineas
}
