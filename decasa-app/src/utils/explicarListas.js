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
import { num, divisor, cuentaPool, cuentaVenta, laParte, partePagada, mediosConFranquicia, restauracionYMeta } from './reglasComision'

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

  // Lo primero, la suma: Manuela en septiembre veía "su parte del pool
  // $674.261" y se pensaba que era eso lo que se pagaba, cuando el total de
  // $892.262 era pool + restauración + lo de los independientes (2026-11-04).
  const partes = desgloseDelBloque(b)
  if (partes.length > 1) {
    lineas.push(`Se le paga ${pesos(b.lista)} = ${partes.map(x => `${x.nombre.toLowerCase()} ${pesos(x.monto)}`).join(' + ')}.`)
  }

  if (b.es_independiente) {
    const i = b.independiente ?? {}
    lineas.push(`Independiente: cobra el ${num(r.indep_pct_venta)}% fijo de sus ventas${r.iva_en_venta ? ` (÷ ${divisor(r.iva_divisor)})` : ''}, sin meta ni pool.`)
    lineas.push(`De sus ventas listas sin pagar: ${pesos(i.por_pagar_ventas)}. Del bolsón de restauraciones: ${pesos(i.por_pagar_bolson)}.`)
  } else {
    const delPool = suma(['pool', 'parte_pool'])
    // Restauraciones que ayudaron a llegar a la meta (regla restauracion_ayuda_meta):
    // ese mes van dentro del pool como ventas, no aparte.
    const restEnPool = ordenes.filter(o => o.forma_pago === 'pool' && (o.tipo_orden === 'restauracion' || String(o.orden_referencia ?? '').startsWith('R-')))
    if (restEnPool.length && r.restauracion_ayuda_meta && !r.restauracion_suma_meta) {
      lineas.push(`${restEnPool.map(o => o.orden_referencia).join(', ')}: las ventas solas no llegaban a la meta y con las restauraciones sí, así que este mes cuentan como ventas: entran al pool con el IVA quitado y no se pagan aparte.`)
    }
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
      if (suma(['parte_pool']) > 0 && suma(['pool']) === 0) {
        lineas.push('No tiene ventas propias que ya cuenten este mes, pero es del equipo: su parte del pool se le paga entera, en un renglón sin orden.')
      }
      lineas.push(n(c.ya_pagado_pool) > 0
        ? `Ya se le pagó de este pool ${pesos(c.ya_pagado_pool)}: queda ${pesos(delPool)} del pool.`
        : `Del pool le tocan ${pesos(delPool)} (es solo una parte; lo demás va abajo).`)
    } else if (c.por_pool) {
      // Es de un equipo con pool, pero este mes no hubo pool: solo cobra lo de abajo.
      if (b.periodicidad === 'trimestral') {
        lineas.push(`${b.tienda_nombre} es trimestral (${c.trimestre ?? ''}) y el trimestre no pasó de la meta: no hay pool. Solo cobra lo de abajo.`)
        lineas.push(...trimestreTexto(b, r, tienda?.pool?.trimestre, { comision_pool: 0 }))
      } else {
        lineas.push(`${b.tienda_nombre} no llegó a la meta de ${mesEnPalabras(b.mes)} (con ${partePagada(r.meta_req_pagado_pct)}: ${pesos(c.total_tienda_mes)} de ${pesos(c.meta_tienda)}): no hay pool. Solo cobra lo de abajo.`)
      }
      lineas.push(...movimientosTexto(p, b))
    }
    lineas.push(...otrosCaminos(b, r, ordenes))
  }

  lineas.push(...otrasTexto(ordenes))
  lineas.push(...fueraDelPoolTexto(b))

  lineas.push(`Listo para pagar de ${mesEnPalabras(b.mes)}: ${pesos(b.lista)}. `
    + (b.atrasada ? `ATRASADA: se debía pagar el ${fecha(b.se_cobra_el)}.` : `Se paga el ${fecha(b.se_cobra_el)}.`))
  lineas.push(...pendientesTexto(b, r))
  if (n(b.se_descuenta) > 0 || n(b.debe_al_corte) > 0) {
    lineas.push(`Anticipos: debía ${pesos(b.debe_al_corte)} a este corte`
      + (n(b.abonos) > 0 ? ` (incluye ${pesos(b.abonos)} de abonos de esta bonificación ya entregados)` : '')
      + `. Se descuentan ${pesos(b.se_descuenta)} → se le entregan ${pesos(b.entregar)}.`)
  }
  return lineas
}

/**
 * Lo que no es pool: restauraciones, individual, lo cubierto, lo de los
 * independientes y lo que no sabemos nombrar. Lo usan Listas y el Excel de la
 * tienda (explicacionDelMes), para que digan lo mismo.
 */
function otrosCaminos(b, r, ordenes) {
  const suma = formas => ordenes.filter(o => formas.includes(o.forma_pago)).reduce((s, o) => s + n(o.monto_comision), 0)
  const lineas = []
  {
    const rest = suma(['restauracion_5', 'restauracion_equipo'])
    if (rest > 0) lineas.push(`Restauraciones: ${pesos(rest)} (${num(r.pct_restauracion)}% de su valor${r.iva_en_restauracion ? '' : ', sin quitarle el IVA'}${suma(['restauracion_equipo']) > 0 ? '; las de la tienda se parten entre los que estaban ese día' : ''}).`)
    const ind = suma(['sin_meta_5'])
    if (ind > 0) {
      const porque = {
        no_reparte: `en ${b.tienda_nombre} no se reparte en equipo: cada uno cobra lo suyo`,
        sin_meta: `${b.tienda_nombre} no tiene meta: cada uno cobra lo suyo`,
        fuera_del_equipo: `vendió en ${b.tienda_nombre} sin ser de su equipo ese mes: cobra como cualquiera de fuera, sin entrar al pool`,
      }[b.motivo_individual]
      lineas.push(`Individual: ${pesos(ind)} = ventas ${cuentaVenta(r, 'pct_individual')}${porque ? ` (${porque})` : ' (no va por pool)'}.`)
    }
    const rem = suma(['reemplazo_ventas'])
    if (rem > 0) lineas.push(`Lo que vendió cubriendo a alguien: ${pesos(rem)} = ventas ${cuentaVenta(r, 'pct_reemplazo')} (no suma a la meta ni al pool).`)
    const ab = suma(['abono_almacen'])
    if (ab > 0) {
      // Cuánto es por cada una: la R-1103 de Henry ($1.000.000) con Norte daba
      // $16.667 a cada uno y nadie entendía de dónde (2026-11-04).
      const delInd = ordenes.filter(o => o.forma_pago === 'abono_almacen')
      lineas.push(`Lo que compartieron independientes con la tienda: ${pesos(ab)}. Por cada una, la tienda cobra un solo ${num(r.almacen_pct)}% `
        + `y se parte entre los que estaban ese día (aquí ${delInd.length} ${delInd.length !== 1 ? 'órdenes' : 'orden'}); `
        + `el independiente cobra su ${num(r.indep_pct_bolson)}% aparte, en su bolsón.`)
    }
  }
  return lineas
}

// Lo que no sabemos explicar no se calla: sale con su nombre y su plata
// (si aparece una forma nueva, la prueba ExplicarListasCubreTodoTest avisa).
function otrasTexto(ordenes) {
  const otras = ordenes.filter(o => !FORMAS_EXPLICADAS.includes(o.forma_pago))
  return otras.length
    ? [`Otros renglones (${[...new Set(otras.map(o => o.forma_pago))].join(', ')}): ${pesos(otras.reduce((s, o) => s + n(o.monto_comision), 0))}.`]
    : []
}

function fueraDelPoolTexto(b) {
  return (b.ordenes_fuera_del_pool ?? []).map(f =>
    `${f.referencia ?? 'Una orden'} (${pesos(f.valor)}) la sacó un supervisor del pool: no suma a la meta ni paga bonificación${f.motivo ? ` (motivo: ${f.motivo})` : ''}.`)
}

/**
 * De qué está hecho lo listo de un mes: [{ nombre, monto }] en el orden en que
 * se explica (pool, restauraciones, lo de independientes, individual…). Suma
 * lo listo del bloque; la pantalla lo pone debajo del mes sin abrirlo.
 */
export function desgloseDelBloque(b) {
  const ordenes = b.ordenes_listas ?? []
  const suma = formas => ordenes.filter(o => formas.includes(o.forma_pago)).reduce((s, o) => s + n(o.monto_comision), 0)
  let partes
  if (b.es_independiente && b.independiente) {
    partes = [
      { nombre: 'Sus ventas', monto: n(b.independiente.por_pagar_ventas) },
      { nombre: 'Bolsón de restauraciones', monto: n(b.independiente.por_pagar_bolson) },
    ]
  } else {
    partes = [
      { nombre: 'Pool', monto: suma(['pool', 'parte_pool']) },
      { nombre: 'Restauraciones', monto: suma(['restauracion_5', 'restauracion_equipo']) },
      { nombre: 'De independientes', monto: suma(['abono_almacen']) },
      { nombre: 'Individual', monto: suma(['sin_meta_5']) },
      { nombre: 'Cubriendo a alguien', monto: suma(['reemplazo_ventas']) },
      { nombre: 'Otros', monto: ordenes.filter(o => !FORMAS_EXPLICADAS.includes(o.forma_pago)).reduce((s, o) => s + n(o.monto_comision), 0) },
    ]
  }
  return partes.filter(x => Math.round(x.monto) > 0)
}

/**
 * Las formas de pago que explicacionDelBloque sabe decir. Tienen que ser las
 * mismas que ComisionController::FORMAS_DE_PAGO: una prueba del backend lee
 * este archivo y falla si aparece una forma nueva sin su explicación.
 */
export const FORMAS_EXPLICADAS = [
  'pool', 'parte_pool', 'sin_meta_5', 'reemplazo_ventas', 'restauracion_5',
  'restauracion_equipo', 'abono_almacen', 'fuera_del_pool', 'bolson_restauraciones',
]

/** Las órdenes del mes que todavía no entran, una por una y con lo que les falta. */
function pendientesTexto(b, r) {
  const lista = b.ordenes_pendientes ?? []
  if (!lista.length) {
    return n(b.pendientes) > 0
      ? [`${b.pendientes} orden(es) de este mes todavía no entran (el cliente no ha pagado ${laParte(r.req_pagado_pct)}): no están en este pago.`]
      : []
  }
  // "Todavía" solo si pueden entrar después: las de un mes que no llegó a la meta ya no.
  const sinMetaMes = b.cuenta?.por_pool && b.cuenta?.meta_cumplida === false && b.periodicidad !== 'trimestral'
  const lineas = [sinMetaMes && lista.every(o => o.cumple_pago)
    ? `${lista.length} orden(es) de este mes no bonifican:`
    : `${lista.length} orden(es) de este mes no entran en este pago:`]
  for (const o of lista) {
    const quien = `${o.referencia ?? 'Orden'}${o.cliente ? ` (${o.cliente})` : ''}`
    if (o.cumple_pago) {
      // Los meses de Listas ya terminaron: si la tienda no llegó, ya no va a llegar.
      const sinMeta = b.cuenta?.por_pool && b.cuenta?.meta_cumplida === false && b.periodicidad !== 'trimestral'
      lineas.push(sinMeta
        ? `   ${quien}: ya pagó ${laParte(r.req_pagado_pct)}, pero la tienda no llegó a la meta de ${mesEnPalabras(b.mes)}: no bonifica.`
        : `   ${quien}: ya pagó ${laParte(r.req_pagado_pct)}; espera ${b.periodicidad === 'trimestral' ? 'al cierre del trimestre' : 'a que la tienda llegue a la meta'}.`)
    } else {
      const falta = Math.max(0, Math.ceil(n(o.valor_total) * n(r.req_pagado_pct) - n(o.pagado)))
      lineas.push(`   ${quien}: ha pagado ${pesos(o.pagado)} de ${pesos(o.valor_total)}; le faltan ${pesos(falta)} para ${laParte(r.req_pagado_pct)}. Cuando los pague entra en el próximo pago.`)
    }
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
    + (saldo > 0
      ? `. El trimestre quedó ${firmado(saldo)}: ${pesos(saldo)} ${cuentaPool(r)} = pool de ${pesos(c.comision_pool)}.`
      : `. El trimestre quedó ${firmado(saldo)}: debajo de la meta, sin pool.`))
  if (n(c.comision_pool) > 0) {
    lineas.push('Ese pool se reparte por los días que estuvo cada uno en los tres meses: a cada mes le toca lo de sus días, aunque ese mes solo no haya llegado.')
  }
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

// ── El Excel de una tienda en un mes (utils/excelTienda.js) ───────────────────
//
// Para mandárselo a los vendedores (dueño, 2026-11-05: "un Excel de agosto de
// El Edén y se lo envío a Sebastián y Gladys"). A diferencia de Listas, un mes
// puede estar ya pagado, listo, a medias o sin llegar al día de pago: cada
// persona tiene que poder leer qué le toca, en qué va y por qué. Sale de
// GET /comisiones/libro-tienda (la misma cuenta del resumen y del pago).

/**
 * Las líneas que explican el mes de una persona en la tienda.
 *
 * @param {Object} d  la respuesta de /comisiones/libro-tienda
 * @param {Object} p  la persona (d.personas[i])
 * @param {Object} r  las reglas de ese mes, tienda y persona
 */
/** 26 → "26", 4,5 → "4,5": los días no son una fracción como los porcentajes. */
const diasTexto = v => Number(v).toLocaleString('es-CO', { maximumFractionDigits: 1 })

export function explicacionDelMes(d, p, r) {
  const c = p.cuenta ?? {}
  const ordenes = p.ordenes ?? []
  const suma = formas => ordenes.filter(o => formas.includes(o.forma_pago)).reduce((s, o) => s + n(o.monto_comision), 0)
  const mes = mesEnPalabras(d.mes)
  const tienda = d.tienda_nombre
  const pool = d.tienda?.pool ?? null
  const trimestral = (p.periodicidad ?? d.periodicidad) === 'trimestral'
  const b = { ...p, mes: d.mes, tienda_id: d.tienda_id, tienda_nombre: tienda, periodicidad: p.periodicidad ?? d.periodicidad }
  const lineas = []

  // 1. Cuánto le da el mes y de qué está hecho.
  const partes = desgloseDelBloque({ ordenes_listas: ordenes })
  lineas.push(partes.length > 1
    ? `Bonificación de ${mes}: ${pesos(p.comision_mes)} = ${partes.map(x => `${x.nombre.toLowerCase()} ${pesos(x.monto)}`).join(' + ')}.`
    : `Bonificación de ${mes}: ${pesos(p.comision_mes)}${partes.length ? ` (${partes[0].nombre.toLowerCase()})` : ''}.`)

  // 2. En qué va: pagado, listo, pendiente o sin llegar el día.
  lineas.push(...estadoDelMes(d, p))

  // 3. Sus días: el pool se parte por los días que estuvo cada uno.
  if (c.por_pool && p.dias != null) {
    const iguales = r.reparto_pool === 'iguales'
    if (n(p.dias) >= n(d.dias_del_mes)) {
      lineas.push(`Estuvo en ${tienda} todo el mes (${diasTexto(p.dias)} días).`)
    } else {
      lineas.push(`Estuvo en ${tienda} ${diasTexto(p.dias)} de los ${d.dias_del_mes} días del mes`
        + (iguales ? ' (en esta tienda el pool se parte en partes iguales, no por días).' : ': el pool se parte por los días que estuvo cada uno, así que le toca la parte de esos días.'))
      if (d.cerrada_en && String(d.cerrada_en).slice(0, 7) === d.mes) {
        lineas.push(`${tienda} cerró el ${fecha(d.cerrada_en)}: desde ese día ya nadie suma días en ella.`)
      } else if (!(p.movimientos ?? []).length) {
        lineas.push('Los días salen del equipo de la tienda (desde qué fecha está en él) y de los reemplazos registrados en Bonificaciones.')
      }
    }
  }
  lineas.push(...movimientosTexto(p, b))

  // 4. El pool de la tienda.
  if (c.por_pool) {
    if (trimestral) {
      const q = pool?.trimestre
      if (n(c.comision_pool) > 0) {
        lineas.push(`${tienda} es trimestral (${c.trimestre ?? ''}): los tres meses se juntan en un solo pool: ${pesos(c.comision_pool)}.`)
        lineas.push(...trimestreTexto(b, r, q, c))
        lineas.push(`Su pedazo de ${mes}: ${c.parte_dias} días de ${c.partes_dias} días de todo el equipo en el trimestre = ${pesos(c.comision_asesor)}.`)
      } else {
        lineas.push(q?.en_curso
          ? `${tienda} es trimestral (${c.trimestre ?? ''}): el pool se calcula al cerrar el trimestre (${fecha(q.se_liquida_el)}). Por ahora no hay pool.`
          : `${tienda} es trimestral (${c.trimestre ?? ''}) y el trimestre no pasó de la meta: no hay pool.`)
        lineas.push(...trimestreTexto(b, r, q, { comision_pool: 0 }))
      }
    } else if (c.meta_cumplida) {
      lineas.push(`${tienda} llegó a la meta de ${mes}: con ${partePagada(r.meta_req_pagado_pct)} vendió ${pesos(c.total_tienda_mes)} y la meta era ${pesos(c.meta_tienda)}.`)
      lineas.push(`Pool de la tienda = (${pesos(c.total_tienda_mes)} − ${pesos(c.meta_tienda)}) ${cuentaPool(r)} = ${pesos(c.comision_pool)}.`)
      lineas.push(r.reparto_pool === 'iguales'
        ? `Su parte: partes iguales entre el equipo = ${pesos(c.comision_asesor)}.`
        : `Su parte: ${pesos(c.comision_pool)} × ${c.parte_dias} días suyos ÷ ${c.partes_dias} días de todo el equipo = ${pesos(c.comision_asesor)}.`)
      if (suma(['parte_pool']) > 0 && suma(['pool']) === 0) {
        lineas.push('No tiene ventas propias que cuenten este mes, pero es del equipo: su parte del pool se le paga entera, en un renglón sin orden.')
      }
    } else {
      const falta = Math.max(0, n(c.meta_tienda) - n(c.total_tienda_mes))
      lineas.push(`${tienda} no llegó a la meta de ${mes}: con ${partePagada(r.meta_req_pagado_pct)} lleva ${pesos(c.total_tienda_mes)} de ${pesos(c.meta_tienda)} (le faltan ${pesos(falta)}). Sin meta no hay pool`
        + (d.llego_el_dia ? '.' : '; todavía puede llegar antes del día de pago.'))
    }
    if (!trimestral && n(pool?.sin_mitad) > 0) {
      lineas.push(`No cuentan todavía ${pesos(pool.sin_mitad)} de ventas de la tienda (${pool.ordenes_sin_mitad} orden${pool.ordenes_sin_mitad !== 1 ? 'es' : ''}) porque el cliente no ha pagado ${laParte(r.meta_req_pagado_pct)}: `
        + (c.meta_cumplida ? 'cuando las paguen, el pool crece.' : 'cuando las paguen, la tienda se acerca a la meta.'))
    }
    const restEnPool = ordenes.filter(o => o.forma_pago === 'pool' && (o.tipo_orden === 'restauracion' || String(o.orden_referencia ?? '').startsWith('R-')))
    if (restEnPool.length && r.restauracion_ayuda_meta && !r.restauracion_suma_meta) {
      lineas.push(`${restEnPool.map(o => o.orden_referencia).join(', ')}: las ventas solas no llegaban a la meta y con las restauraciones sí, así que este mes cuentan como ventas: entran al pool con el IVA quitado y no se pagan aparte.`)
    }
  }

  // 5. Lo demás: restauraciones, individual, lo cubierto, lo de los independientes.
  lineas.push(...otrosCaminos(b, r, ordenes))
  const restSinMeta = ordenes.filter(o => o.es_restauracion && o.estado === 'pendiente' && o.req_50_pct && c.por_pool && c.meta_cumplida === false && !trimestral)
  if (restSinMeta.length && d.llego_el_dia) {
    lineas.push(`${restSinMeta.map(o => o.orden_referencia ?? 'Una restauración').join(', ')}: las restauraciones ${restauracionYMeta(r)}. Como la tienda no llegó a la meta, no se pagan.`)
  }
  lineas.push(...otrasTexto(ordenes))
  lineas.push(...fueraDelPoolTexto(b))

  // 6. La tarjeta.
  const ds = p.desglose ?? {}
  if (n(ds.costo_datafono) > 0) {
    lineas.push(`En sus órdenes se pagaron ${pesos(ds.pagado_tarjeta)} con ${mediosConFranquicia(r)}: la franquicia se queda con el ${num(r.franquicia_pct)}% (${pesos(ds.costo_datafono)}) y sobre eso nadie bonifica. Por eso a esas órdenes se les resta antes de calcular (columna "¿Por qué se resta o no la tarjeta?").`)
  } else if (ordenes.some(o => o.forma_pago !== 'parte_pool' && n(o.pagado) > 0)) {
    lineas.push(`Ninguna de sus órdenes se pagó con ${mediosConFranquicia(r)}: no se les resta nada por la franquicia.`)
  }

  // 7. Las órdenes que todavía no entran, una por una.
  lineas.push(...pendientesDelMes(d, p, r))

  // 8. Anticipos y abonos.
  if (p.pago && (n(p.pago.se_descuenta) > 0 || n(p.pago.debe_al_corte) > 0)) {
    lineas.push(`Anticipos y abonos: debía ${pesos(p.pago.debe_al_corte)} a este corte. Al pagar se descuentan ${pesos(p.pago.se_descuenta)} → se le entregan ${pesos(p.pago.entregar)}.`)
    if (n(p.pago.sigue_debiendo) > 0) lineas.push(`Después de pagarle sigue debiendo ${pesos(p.pago.sigue_debiendo)}: se descuenta en el próximo pago.`)
  } else if (n(p.abonos_del_mes) > 0) {
    lineas.push(`De esta bonificación ya se le dio un abono de ${pesos(p.abonos_del_mes)}: se descuenta al pagarla.`)
  }
  return lineas
}

/** Pagado, listo, pendiente o el día de pago que no llega: lo primero que pregunta cada uno. */
export function estadoDelMes(d, p) {
  const lineas = []
  if (!d.llego_el_dia) {
    lineas.push(`Todavía no llega el día de pago (${fecha(d.se_cobra_el)}): esto es cómo va. Puede cambiar si los clientes pagan lo que deben o si se vende más.`)
    return lineas
  }
  if (n(p.lista) > 0) {
    lineas.push(`LISTO PARA PAGAR: ${pesos(p.lista)}. ` + (p.pago?.atrasada
      ? `Está atrasado: se debía pagar el ${fecha(d.se_cobra_el)}.`
      : `Se paga el ${fecha(d.se_cobra_el)}.`))
    if (n(p.pago?.otros_meses) > 0) {
      lineas.push(`En Listas tiene ${p.pago.otros_meses} pago(s) más (de otro mes o de otra tienda): se pagan del mes más viejo al más nuevo.`)
    }
  }
  const { espera, noSePaga } = partesDelMes(d, p)
  if (n(p.pagado) > 0) {
    lineas.push(n(p.lista) > 0 || espera > 0
      ? `Ya se le pagaron ${pesos(p.pagado)} de este mes.`
      : `YA SE PAGÓ: ${pesos(p.pagado)}. No queda nada de este mes por pagarle.`)
  }
  if (espera > 0) {
    lineas.push(`Todavía no se puede pagar: ${pesos(espera)} (el porqué va abajo, orden por orden).`)
  }
  if (noSePaga > 0) {
    lineas.push(`No se paga: ${pesos(noSePaga)}. ${porQueNoSePaga(d, p)}`)
  }
  if (n(p.comision_mes) <= 0) {
    lineas.push('Este mes no le da bonificación en esta tienda (el porqué va abajo).')
  }
  return lineas
}

/**
 * Si una orden que espera ya no se va a pagar nunca: el día de pago pasó, el
 * cliente ya pagó la mitad y lo que falta es la meta, que en un mes cerrado ya
 * no va a llegar (o el trimestre cerró sin pool). Unicentro agosto 2026: la
 * R-1100 salía "todavía no se puede pagar" y no se iba a pagar.
 */
function yaNoSePaga(o, p, d) {
  if (!d.llego_el_dia || o.estado !== 'pendiente') return false
  if (o.forma_pago !== 'parte_pool' && !o.req_50_pct) return false
  const c = p.cuenta ?? {}
  if (!c.por_pool) return false
  if ((p.periodicidad ?? d.periodicidad) === 'trimestral') {
    const q = d.tienda?.pool?.trimestre
    return !!q && !q.en_curso && n(c.comision_pool) <= 0
  }
  return c.meta_cumplida === false
}

function porQueNoSePaga(d, p) {
  return (p.periodicidad ?? d.periodicidad) === 'trimestral'
    ? 'El trimestre cerró sin pasar de la meta.'
    : `La tienda no llegó a la meta de ${mesEnPalabras(d.mes)} y el mes ya cerró.`
}

/**
 * Lo del mes partido en lo que ya se pagó, lo listo, lo que espera (el
 * cliente no ha pagado la mitad o no llega el día) y lo que ya no se va a
 * pagar. Suma la comisión del mes.
 */
export function partesDelMes(d, p) {
  const noSePaga = Math.round((p.ordenes ?? []).filter(o => yaNoSePaga(o, p, d)).reduce((s, o) => s + n(o.monto_comision), 0))
  return {
    pagado: n(p.pagado),
    lista: n(p.lista),
    espera: Math.max(0, n(p.pendiente) - noSePaga),
    noSePaga,
  }
}

/** Una palabra para la tabla: Pagado, Listo, A medias, Pendiente, Cómo va, Sin comisión. */
export function estadoCorto(d, p) {
  const { espera, noSePaga } = partesDelMes(d, p)
  if (!d.llego_el_dia) return n(p.comision_mes) > 0 ? 'Cómo va (no ha llegado el día)' : 'Sin bonificación por ahora'
  if (n(p.comision_mes) <= 0 || (noSePaga > 0 && noSePaga >= n(p.comision_mes))) return 'Sin bonificación'
  if (n(p.lista) > 0) return p.pago?.atrasada ? 'Listo para pagar (atrasado)' : 'Listo para pagar'
  if (n(p.pagado) > 0 && espera <= 0) return 'Ya se pagó'
  if (n(p.pagado) > 0) return 'Pagado en parte; falta'
  return 'Pendiente'
}

/** Por qué una orden está pagada, lista o esperando: la columna "Estado" del Excel de la tienda. */
export function estadoDeOrden(o, p, d, r) {
  if (o.forma_pago === 'fuera_del_pool') return 'Fuera del pool: no bonifica'
  if (o.estado === 'pagada') return 'Pagada'
  if (o.estado === 'lista') return 'Lista para pagar'
  const trimestral = (p.periodicidad ?? d.periodicidad) === 'trimestral'
  if (o.forma_pago !== 'parte_pool' && !o.req_50_pct) {
    const falta = Math.max(0, Math.ceil(n(o.valor_total_orden) * n(r.req_pagado_pct) - n(o.pagado)))
    return `Falta que el cliente pague ${laParte(r.req_pagado_pct)}: le faltan ${pesos(falta)}`
  }
  if (!d.llego_el_dia) return `Se paga el ${fecha(d.se_cobra_el)}`
  if (o.fecha_disponible && o.fecha_disponible > d.hoy) return `Se paga el ${fecha(o.fecha_disponible)}`
  if (yaNoSePaga(o, p, d)) return trimestral ? 'El trimestre no pasó de la meta: no se paga' : 'La tienda no llegó a la meta: no se paga'
  if (trimestral) return 'Espera el cierre del trimestre'
  return 'Pendiente'
}

/**
 * Las órdenes del mes que todavía no entran. Las que esperan que el cliente
 * pague la mitad van una por una con lo que les falta; las que ya la tienen
 * van juntas en una línea (en octubre de El Edén eran 15 renglones iguales).
 */
function pendientesDelMes(d, p, r) {
  const lista = p.ordenes_pendientes ?? []
  if (!lista.length) return []
  const c = p.cuenta ?? {}
  const trimestral = (p.periodicidad ?? d.periodicidad) === 'trimestral'
  const q = d.tienda?.pool?.trimestre
  const refs = os => os.map(o => o.referencia ?? 'orden').join(', ')
  const lineas = []

  const sinMitad = lista.filter(o => !o.cumple_pago)
  if (sinMitad.length) {
    lineas.push(`${sinMitad.length} orden(es) suyas esperan que el cliente pague ${laParte(r.req_pagado_pct)}: hasta entonces no suman a la meta ni bonifican; cuando la paguen, entran en el próximo pago.`)
    for (const o of sinMitad) {
      const falta = Math.max(0, Math.ceil(n(o.valor_total) * n(r.req_pagado_pct) - n(o.pagado)))
      lineas.push(`   ${o.referencia ?? 'Orden'}${o.cliente ? ` (${o.cliente})` : ''}: ha pagado ${pesos(o.pagado)} de ${pesos(o.valor_total)}; le faltan ${pesos(falta)}.`)
    }
  }

  const conMitad = lista.filter(o => o.cumple_pago)
  if (conMitad.length) {
    const cuantas = `${conMitad.length} orden(es) suyas ya tienen ${laParte(r.req_pagado_pct)} pagada (${refs(conMitad)})`
    if (!d.llego_el_dia) {
      lineas.push(trimestral && q?.en_curso
        ? `${cuantas}: se pagan al cerrar el trimestre (${fecha(q.se_liquida_el)}) si el trimestre pasa de la meta.`
        : `${cuantas}: se pagan el ${fecha(d.se_cobra_el)}${c.por_pool && !c.meta_cumplida ? ' si la tienda llega a la meta' : ''}.`)
    } else if (c.por_pool && (trimestral ? (q && !q.en_curso && n(c.comision_pool) <= 0) : c.meta_cumplida === false)) {
      lineas.push(`${cuantas}, pero no bonifican: ${porQueNoSePaga(d, p).toLowerCase()}`)
    } else {
      lineas.push(`${cuantas}: esperan ${trimestral ? 'al cierre del trimestre' : 'a que la tienda llegue a la meta'}.`)
    }
  }
  return lineas
}

/**
 * El mes de un independiente (GET /comisiones/libro-tienda?independiente_id=N).
 * No es de ninguna tienda: cobra su % de sus ventas y su parte del bolsón de
 * restauraciones de todos (dueño, 2026-11-05: "también los independientes, a
 * cada uno, porque no pertenecen a tiendas").
 */
export function explicacionDelIndependiente(d, r) {
  const mes = mesEnPalabras(d.mes)
  const ordenes = d.ordenes ?? []
  const bolson = d.bolson ?? {}
  const lineas = []

  lineas.push(n(d.de_sus_ventas) > 0 && n(d.del_bolson) > 0
    ? `Bonificación de ${mes}: ${pesos(d.comision_mes)} = sus ventas ${pesos(d.de_sus_ventas)} + bolsón de restauraciones ${pesos(d.del_bolson)}.`
    : `Bonificación de ${mes}: ${pesos(d.comision_mes)}${n(d.del_bolson) > 0 ? ' (todo del bolsón de restauraciones)' : ''}.`)
  lineas.push(...estadoDelMes(d, d))
  if (d.llego_el_dia && n(d.lista) > 0 && (n(d.por_pagar_ventas) > 0 || n(d.por_pagar_bolson) > 0)) {
    lineas.push(`Lo listo sale de: sus ventas ${pesos(d.por_pagar_ventas)} + bolsón ${pesos(d.por_pagar_bolson)}.`)
  }

  lineas.push(`Es independiente: no pertenece a ninguna tienda, no tiene meta ni pool y no depende de lo que venda nadie más (salvo el bolsón).`)

  // Sus ventas.
  const ventas = ordenes.filter(o => !o.es_restauracion)
  if (ventas.length) {
    lineas.push(`Sus ventas: ${ventas.length} orden(es); a cada una le toca ${cuentaVenta(r, 'indep_pct_venta')}, sobre su valor menos la franquicia de la tarjeta → ${pesos(d.de_sus_ventas)} en el mes.`)
  } else {
    lineas.push('Este mes no tiene ventas propias (solo restauraciones): todo lo suyo viene del bolsón.')
  }

  // El bolsón.
  const deTodos = (bolson.restauraciones ?? []).length
  const quienes = (bolson.independientes ?? []).join(' y ')
  lineas.push(`Bolsón de restauraciones de ${mes}: se juntan TODAS las restauraciones de los independientes (${quienes})`
    + `${r.bolson_incluye_almacenes ? ' y las que subieron los almacenes' : ''}, la suba quien la suba: ${deTodos} por ${pesos(bolson.total)} (hoja "Bolsón").`)
  lineas.push(r.bolson_modo === 'se_reparte'
    ? `El ${num(r.indep_pct_bolson)}% del bolsón (sin quitarle el IVA) se reparte entre los independientes: su parte = ${pesos(d.del_bolson)}.`
    : `Cada independiente cobra el ${num(r.indep_pct_bolson)}% del bolsón completo, sin quitarle el IVA (es mano de obra): ${pesos(bolson.total)} × ${num(r.indep_pct_bolson)}% = ${pesos(d.del_bolson)}.`)
  if (n(bolson.listo) < n(bolson.total)) {
    lineas.push(d.llego_el_dia
      ? `De eso ya entra ${pesos(bolson.listo)} (las restauraciones cuyo cliente ya pagó ${laParte(r.req_pagado_pct)}): su parte lista del bolsón es ${pesos(d.del_bolson_listo)}. El resto entra cuando los clientes paguen.`
      : `Al día de pago entra solo lo que el cliente ya haya pagado ${laParte(r.req_pagado_pct)}.`)
  }

  // Lo compartido con un almacén.
  const conAlmacen = ordenes.filter(o => o.almacen)
  if (conAlmacen.length) {
    lineas.push(`${conAlmacen.map(o => o.orden_referencia).join(', ')}: compartida(s) con ${[...new Set(conAlmacen.map(o => o.almacen))].join(', ')}. Él cobra lo suyo igual; el almacén cobra su propio ${num(r.almacen_pct)}% aparte, partido entre su equipo.`)
  }

  // La tarjeta.
  const tarjeta = ordenes.reduce((s, o) => s + n(o.pagado_tarjeta), 0)
  const franquicia = ordenes.reduce((s, o) => s + n(o.costo_datafono), 0)
  if (franquicia > 0) {
    lineas.push(`En sus órdenes se pagaron ${pesos(tarjeta)} con ${mediosConFranquicia(r)}: la franquicia se queda con el ${num(r.franquicia_pct)}% (${pesos(franquicia)}) y sobre eso nadie bonifica.`)
  }

  // Sus órdenes que esperan la mitad.
  const sinMitad = ordenes.filter(o => o.estado === 'pendiente' && !o.req_50_pct)
  if (sinMitad.length) {
    lineas.push(`${sinMitad.length} orden(es) suyas esperan que el cliente pague ${laParte(r.req_pagado_pct)}; cuando la paguen, entran en el próximo pago:`)
    for (const o of sinMitad) {
      const falta = Math.max(0, Math.ceil(n(o.valor_total_orden) * n(r.req_pagado_pct) - n(o.pagado)))
      lineas.push(`   ${o.orden_referencia ?? 'Orden'}${o.cliente_nombre ? ` (${o.cliente_nombre})` : ''}: ha pagado ${pesos(o.pagado)} de ${pesos(o.valor_total_orden)}; le faltan ${pesos(falta)}.`)
    }
  }

  // Anticipos.
  if (d.pago && (n(d.pago.se_descuenta) > 0 || n(d.pago.debe_al_corte) > 0)) {
    lineas.push(`Anticipos y abonos: debía ${pesos(d.pago.debe_al_corte)} a este corte. Al pagar se descuentan ${pesos(d.pago.se_descuenta)} → se le entregan ${pesos(d.pago.entregar)}.`)
    if (n(d.pago.sigue_debiendo) > 0) lineas.push(`Después de pagarle sigue debiendo ${pesos(d.pago.sigue_debiendo)}: se descuenta en el próximo pago.`)
  } else if (n(d.abonos_del_mes) > 0) {
    lineas.push(`De esta bonificación ya se le dio un abono de ${pesos(d.abonos_del_mes)}: se descuenta al pagarla.`)
  }
  return lineas
}
