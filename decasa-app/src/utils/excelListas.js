/**
 * El Excel de Comisiones → Listas: todo lo que se va a pagar, explicado.
 *
 * Lo piden para pagar y para revisar: quien lo abre tiene que poder ver, sin
 * abrir la app, a quién se le paga, de qué órdenes sale cada peso, por qué a
 * una orden se le restó la franquicia de la tarjeta y a otra no, dónde entra
 * el ÷ 1,19 del IVA, de dónde sale el pool de cada tienda y cuánto se
 * descuenta de anticipos y abonos.
 *
 * No calcula nada de plata: todo sale de GET /comisiones/por-pagar?detalle=1,
 * que es la misma cuenta que pinta Listas y que se paga (loQueHayPorPagar).
 * Los totales del libro son los de esa respuesta; si una suma de filas no da
 * lo mismo (el redondeo del bolsón de los independientes), se dice en su
 * propio renglón para que cuadre peso a peso.
 *
 * Usa las piezas de formato del Excel del mes (utils/excelComisiones).
 */

import {
  FORMA, CANAL, VERDE_CLARO, GRIS_TEXTO, AMBAR,
  mesEnPalabras, fecha, pesos, n, titulo, seccion, tabla, fichas, explicar,
} from './excelComisiones'
import { reglasPara, num, divisor, cuentaPool, cuentaVenta, mediosConFranquicia, laParte, partePagada, cuandoSePaga } from './reglasComision'

const MEDIO = {
  efectivo: 'efectivo', transferencia: 'transferencia', tarjeta: 'tarjeta', addi: 'Addi',
  otro: 'otro', sin_metodo: 'sin medio',
}

// Las reglas de cada mes (puede haber varios meses juntos en Listas).
let REGLAS = {}
const R = (mes, tiendaId = null, usuarioId = null) => reglasPara(REGLAS[mes] ?? null, { tiendaId, usuarioId })

// ── Cada orden, en palabras ───────────────────────────────────────────────────

/** Qué es el renglón: venta, restauración, compartida, parte del equipo… */
function tipoDe(o, b) {
  if (o.forma_pago === 'parte_pool') return 'Parte del equipo (sin orden)'
  if (o.forma_pago === 'abono_almacen') return `${o.es_restauracion ? 'Restauración' : 'Venta'} de un independiente con la tienda`
  if (o.forma_pago === 'restauracion_equipo') return 'Restauración repartida en la tienda'
  let t = o.es_restauracion ? 'Restauración' : (o.tipo_orden === 'fv2' || o.es_descuento_especial ? 'Venta FV2' : 'Venta')
  if (o.es_compartida) t += ' compartida'
  if (b.es_independiente && o.almacen) t += ` (con ${o.almacen})`
  return t
}

function mediosTexto(o) {
  const m = o.medios_de_pago ?? {}
  const partes = Object.entries(m).filter(([, v]) => n(v) !== 0).map(([k, v]) => `${MEDIO[k] ?? k} ${pesos(v)}`)
  if (partes.length || o.forma_pago === 'parte_pool') return partes.join(' · ')
  // Las de los independientes no traen el medio uno por uno: solo lo de tarjeta.
  if (n(o.pagado) <= 0) return 'sin pagos'
  const otros = n(o.pagado) - n(o.pagado_tarjeta)
  return [n(o.pagado_tarjeta) > 0 ? `tarjeta o Addi ${pesos(o.pagado_tarjeta)}` : '', otros > 0 ? `efectivo o transferencia ${pesos(otros)}` : '']
    .filter(Boolean).join(' · ')
}

/** Por qué se le restó (o no) la franquicia de la tarjeta. */
function porQueTarjeta(o, r) {
  if (o.forma_pago === 'parte_pool') return ''
  const medios = mediosConFranquicia(r)
  if (n(o.costo_datafono) > 0) {
    return `Pagó ${pesos(o.pagado_tarjeta)} con ${medios}: la franquicia se queda con el ${num(r.franquicia_pct)}% `
      + `(${pesos(o.costo_datafono)}) y sobre eso nadie comisiona. Se le resta a la base.`
  }
  if (n(o.pagado) <= 0) return 'No se resta nada: el cliente todavía no ha pagado.'
  return `No se resta nada: no pagó con ${medios} (solo esos medios cobran franquicia).`
}

/** La base que comisiona: orden − franquicia, y la parte de cada uno si se comparte. */
function baseTexto(o, b, r) {
  if (o.forma_pago === 'parte_pool') return ''
  const entera = n(o.valor_total_orden)
  const base = entera - n(o.costo_datafono)
  let t = n(o.costo_datafono) > 0
    ? `${pesos(entera)} − ${pesos(o.costo_datafono)} de franquicia = ${pesos(base)}`
    : `${pesos(entera)} (sin franquicia)`
  if (Math.abs(base - n(o.valor_orden)) >= 2) {
    // Quien registró se lleva su parte y el compañero el resto (50/50 hoy).
    if (o.es_compartida) t += '; compartida: le toca su parte'
    else if (['abono_almacen', 'restauracion_equipo'].includes(o.forma_pago)) t += '; le toca su pedazo'
    t += ` → ${pesos(o.valor_orden)}`
  }
  return t
}

/** Si a esta comisión se le quita el IVA (÷ 1,19) y dónde. */
function ivaTexto(o, b, r) {
  const dv = divisor(r.iva_divisor)
  if (o.forma_pago === 'parte_pool' || o.forma_pago === 'pool') {
    return `En el pool: (ventas − meta) ÷ ${dv}, no orden por orden`
  }
  const esRest = o.es_restauracion || o.forma_pago === 'restauracion_equipo'
  if (esRest) {
    if (b.es_independiente) return 'Va al bolsón de restauraciones, sin quitarle el IVA'
    return r.iva_en_restauracion ? `Sí: ÷ ${dv}` : 'No: a la restauración no se le quita el IVA'
  }
  if (o.sin_descontar_iva) return 'No: FV2 marcada "no se resta el IVA"'
  return r.iva_en_venta ? `Sí: ÷ ${dv}` : 'No (regla del mes)'
}

/** De dónde sale el monto de este renglón. */
function comoSale(o, b, r) {
  const v = pesos(o.valor_orden), m = pesos(o.monto_comision)
  switch (o.forma_pago) {
    case 'pool':
      return `Pedazo de su parte del pool de la tienda: se le paga sobre sus ventas que ya cuentan, a prorrata del valor → ${m}.`
    case 'parte_pool':
      return `Su parte del pool, entera en este renglón: no tiene una venta propia que ya cuente por la cual pagársela → ${m}.`
    case 'sin_meta_5':
      return o.sin_descontar_iva
        ? `${v} × ${num(r.pct_individual)}% = ${m} (individual, sin restar el IVA).`
        : `${v} ${cuentaVenta(r, 'pct_individual')} = ${m} (individual: no va por pool).`
    case 'restauracion_5':
      if (b.es_independiente) return 'Va al bolsón de restauraciones: se paga en el renglón del bolsón, no aquí.'
      return `${v}${r.iva_en_restauracion ? ` ÷ ${divisor(r.iva_divisor)}` : ''} × ${num(r.pct_restauracion)}% = ${m}. Sin pool ni meta.`
    case 'restauracion_equipo':
      return `Su pedazo de una restauración de la tienda (el ${num(r.pct_restauracion)}% se parte entre los que estaban) → ${m}.`
    case 'abono_almacen':
      return o.es_restauracion
        ? `Lo que dejó un independiente (restauración): ${v} × ${num(r.almacen_pct)}% = ${m}.`
        : `Lo que dejó un independiente: ${v} ${cuentaVenta(r, 'almacen_pct')} = ${m}. No suma a la meta.`
    default:
      return m
  }
}

/** Lo que paga cada renglón de un bloque: en un independiente la restauración va en el bolsón. */
function montoDe(o, b) {
  if (b.es_independiente && o.es_restauracion) return 0
  return n(o.monto_comision)
}

/**
 * Los renglones de un bloque, ya con todo lo que dicen. Los de un
 * independiente llevan además el bolsón y, si hace falta, el redondeo: así la
 * suma da exactamente lo listo del bloque.
 */
function renglonesDe(p, b) {
  const r = R(b.mes, b.tienda_id, p.vendedor_id)
  const filas = (b.ordenes_listas ?? []).map(o => ({
    persona: p.vendedor_nombre, tienda: b.tienda_nombre, mes: b.mes,
    orden: o.forma_pago === 'parte_pool' ? '(sin orden)' : (o.orden_referencia ?? (o.orden_numero ? '#' + o.orden_numero : '')),
    cliente: o.cliente_nombre ?? '',
    canal: CANAL[o.canal] ?? (o.canal ?? ''),
    fecha: fecha(o.fecha_venta),
    tipo: tipoDe(o, b),
    como: b.es_independiente ? (o.es_restauracion ? 'Bolsón de restauraciones' : 'Individual (independiente)') : (FORMA[o.forma_pago] ?? 'Pool del equipo'),
    valor_orden_entera: o.forma_pago === 'parte_pool' ? null : n(o.valor_total_orden),
    pagado: o.forma_pago === 'parte_pool' ? null : n(o.pagado),
    pct_pagado: o.forma_pago === 'parte_pool' ? null : n(o.pct_pagado),
    cumple: o.forma_pago === 'parte_pool' ? '' : (o.req_50_pct ? 'Sí' : 'No'),
    medios: mediosTexto(o),
    tarjeta: o.forma_pago === 'parte_pool' ? null : n(o.pagado_tarjeta),
    franquicia: o.forma_pago === 'parte_pool' ? null : n(o.costo_datafono),
    por_que_tarjeta: porQueTarjeta(o, r),
    base: o.forma_pago === 'parte_pool' ? null : n(o.valor_orden),
    base_texto: baseTexto(o, b, r),
    iva: ivaTexto(o, b, r),
    comision: montoDe(o, b),
    como_sale: comoSale(o, b, r),
  }))

  if (b.es_independiente && b.independiente) {
    const bolson = n(b.independiente.por_pagar_bolson)
    if (bolson > 0) {
      const ya = n(b.independiente.bolson_lista) - bolson
      filas.push({
        persona: p.vendedor_nombre, tienda: b.tienda_nombre, mes: b.mes,
        orden: '(bolsón)', tipo: 'Bolsón de restauraciones', como: 'Bolsón de restauraciones',
        comision: bolson,
        como_sale: `Restauraciones listas de todos los independientes${r.bolson_incluye_almacenes ? ' y de los almacenes' : ''}: `
          + `su ${num(r.indep_pct_bolson)}% ${r.bolson_modo === 'se_reparte' ? 'repartido entre ellos' : 'del total'} = ${pesos(b.independiente.bolson_lista)}`
          + (ya > 0 ? `, menos ${pesos(ya)} ya pagados` : '') + ` → ${pesos(bolson)}. Ver hoja "Por tienda".`,
        iva: 'No: a la restauración no se le quita el IVA',
      })
    }
    // Cada orden se redondea sola y el servidor redondea la suma: la
    // diferencia (uno o dos pesos) va aparte para que cuadre con Listas.
    const suma = filas.reduce((s, f) => s + n(f.comision), 0)
    const ajuste = Math.round(n(b.lista) - suma)
    if (ajuste !== 0) {
      filas.push({
        persona: p.vendedor_nombre, tienda: b.tienda_nombre, mes: b.mes,
        orden: '(redondeo)', tipo: 'Redondeo', comision: ajuste,
        como_sale: 'Cada orden se redondea a pesos por separado y el total se redondea una vez: es la diferencia.',
      })
    }
  }
  return filas
}

/** De dónde sale lo listo de un bloque, en líneas que se pueden rehacer a mano. */
function explicacionDe(p, b, tiendaDe) {
  const r = R(b.mes, b.tienda_id, p.vendedor_id)
  const c = b.cuenta ?? {}
  const ordenes = b.ordenes_listas ?? []
  const suma = formas => ordenes.filter(o => formas.includes(o.forma_pago)).reduce((s, o) => s + n(o.monto_comision), 0)
  const lineas = []

  if (b.es_independiente) {
    const i = b.independiente ?? {}
    lineas.push(`• Independiente: cobra el ${num(r.indep_pct_venta)}% fijo de sus ventas${r.iva_en_venta ? ` (÷ ${divisor(r.iva_divisor)})` : ''}, sin meta ni pool.`)
    lineas.push(`• De sus ventas listas sin pagar: ${pesos(i.por_pagar_ventas)}. Del bolsón de restauraciones: ${pesos(i.por_pagar_bolson)}.`)
  } else {
    const delPool = suma(['pool', 'parte_pool'])
    if (c.por_pool && delPool > 0) {
      const t = tiendaDe(b.mes, b.tienda_id)
      if (b.periodicidad === 'trimestral') {
        lineas.push(`• ${b.tienda_nombre} es trimestral (${c.trimestre ?? ''}): el pool es del trimestre, ya descontada la deuda que traía; ${pesos(c.comision_pool)}. Ver hoja "Por tienda".`)
        lineas.push(`• Su pedazo de ${mesEnPalabras(b.mes)}: ${c.parte_dias} días de ${c.partes_dias} días de todo el equipo en el trimestre = ${pesos(c.comision_asesor)}.`)
      } else {
        lineas.push(`• Pool de ${b.tienda_nombre}: (${pesos(c.total_tienda_mes)} vendido con ${partePagada(r.meta_req_pagado_pct)} − ${pesos(c.meta_tienda)} de meta) ${cuentaPool(r)} = ${pesos(c.comision_pool)}.`)
        lineas.push(r.reparto_pool === 'iguales'
          ? `• Su parte: partes iguales entre el equipo = ${pesos(c.comision_asesor)}.`
          : `• Su parte: ${c.parte_dias} de ${c.partes_dias} días del equipo = ${pesos(c.comision_pool)} × ${c.parte_dias} ÷ ${c.partes_dias} = ${pesos(c.comision_asesor)}.`)
        if (t?.pool?.sin_mitad > 0) {
          lineas.push(`• No cuentan todavía ${pesos(t.pool.sin_mitad)} de ventas de la tienda sin ${partePagada(r.meta_req_pagado_pct)}: cuando las paguen, el pool crece y sale la diferencia.`)
        }
      }
      if (n(c.ya_pagado_pool) > 0) {
        lineas.push(`• Ya se le pagó de este pool ${pesos(c.ya_pagado_pool)}: queda ${pesos(delPool)}.`)
      } else {
        lineas.push(`• Del pool se le paga ahora ${pesos(delPool)}.`)
      }
    }
    const rest = suma(['restauracion_5', 'restauracion_equipo'])
    if (rest > 0) lineas.push(`• Restauraciones: ${pesos(rest)} (${num(r.pct_restauracion)}% de su valor${r.iva_en_restauracion ? '' : ', sin quitarle el IVA'}).`)
    const ind = suma(['sin_meta_5'])
    if (ind > 0) lineas.push(`• Individual (no va por pool): ${pesos(ind)} = ventas ${cuentaVenta(r, 'pct_individual')}.`)
    const ab = suma(['abono_almacen'])
    if (ab > 0) lineas.push(`• Lo que dejaron ventas de independientes con la tienda: ${pesos(ab)}.`)
  }

  lineas.push(`• Listo para pagar de ${mesEnPalabras(b.mes)}: ${pesos(b.lista)}. `
    + (b.atrasada ? `ATRASADA: se debía pagar el ${fecha(b.se_cobra_el)}.` : `Se paga el ${fecha(b.se_cobra_el)}.`))
  if (n(b.pendientes) > 0) {
    lineas.push(`• ${b.pendientes} orden(es) de este mes todavía no entran (el cliente no ha pagado ${laParte(r.req_pagado_pct)}): no están en este pago.`)
  }
  if (n(b.se_descuenta) > 0 || n(b.debe_al_corte) > 0) {
    lineas.push(`• Anticipos: debía ${pesos(b.debe_al_corte)} a este corte`
      + (n(b.abonos) > 0 ? ` (incluye ${pesos(b.abonos)} de abonos de esta comisión ya entregados)` : '')
      + `. Se descuentan ${pesos(b.se_descuenta)} → se le entregan ${pesos(b.entregar)}.`)
  }
  return lineas
}

// ── Columnas de la tabla de órdenes ──────────────────────────────────────────

function columnasOrdenes({ conPersona = false } = {}) {
  return [
    ...(conPersona ? [
      { titulo: 'Persona', campo: 'persona', ancho: 20 },
      { titulo: 'Tienda', campo: 'tienda', ancho: 20 },
      { titulo: 'Mes de venta', campo: f => mesEnPalabras(f.mes), ancho: 15 },
    ] : []),
    { titulo: 'Orden', campo: 'orden', ancho: 11 },
    { titulo: 'Cliente', campo: 'cliente', ancho: 22 },
    { titulo: 'Fecha', campo: 'fecha', ancho: 11 },
    { titulo: 'Tipo', campo: 'tipo', ancho: 18 },
    { titulo: 'Cómo se paga', campo: 'como', ancho: 18 },
    { titulo: 'Valor de la orden', campo: 'valor_orden_entera', fmt: 'pesos', ancho: 15 },
    { titulo: 'Pagado por el cliente', campo: 'pagado', fmt: 'pesos', ancho: 15 },
    { titulo: '% pagado', campo: 'pct_pagado', fmt: 'pct', ancho: 9 },
    { titulo: 'Tiene la mitad', campo: 'cumple', ancho: 9, color: f => f.cumple === 'No' ? AMBAR : null },
    { titulo: 'Cómo pagó', campo: 'medios', ancho: 26 },
    { titulo: 'Con tarjeta o Addi', campo: 'tarjeta', fmt: 'pesos', ancho: 14 },
    { titulo: 'Franquicia restada', campo: 'franquicia', fmt: 'pesos', ancho: 13, total: 'suma' },
    { titulo: '¿Por qué se resta o no la tarjeta?', campo: 'por_que_tarjeta', ancho: 40 },
    { titulo: 'Base que comisiona', campo: 'base', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Cómo sale la base', campo: 'base_texto', ancho: 34 },
    { titulo: 'IVA (÷ 1,19)', campo: 'iva', ancho: 26 },
    { titulo: 'COMISIÓN A PAGAR', campo: 'comision', fmt: 'pesos', ancho: 16, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'De dónde sale', campo: 'como_sale', ancho: 60 },
  ]
}

// ── Las hojas ─────────────────────────────────────────────────────────────────

function hojaResumen(wb, ctx) {
  const d = ctx.datos
  const ws = wb.addWorksheet('Resumen')
  ws.getColumn(1).width = 34
  ws.getColumn(2).width = 22

  titulo(ws, 'Comisiones por pagar',
    `${d.solo_mes ? `Solo ${mesEnPalabras(d.solo_mes)}` : 'Todo lo que hay por pagar'} · generado el ${ctx.generadoEl}${ctx.generadoPor ? ' por ' + ctx.generadoPor : ''}`)

  fichas(ws, [
    ['Último día de pago', fecha(d.dia_de_pago)],
    ['Mes que toca pagar', mesEnPalabras(d.mes_que_toca)],
    ['Personas', d.personas.length, 'entero'],
    ['Comisión lista para pagar', n(d.lista), 'pesos'],
    ['   Anticipos y abonos que se descuentan', n(d.se_descuenta), 'pesos'],
    ['   SE ENTREGA', n(d.entregar), 'pesos'],
  ])

  if (d.atrasados?.length) {
    seccion(ws, 'Meses atrasados', 'Su día de pago ya pasó y no se pagaron. Se pagan primero, del más viejo al más nuevo.')
    tabla(ws, [
      { titulo: 'Mes de venta', campo: a => mesEnPalabras(a.mes), ancho: 34 },
      { titulo: 'Se debía pagar el', campo: a => fecha(a.se_cobra_el), ancho: 22 },
      { titulo: 'Personas', campo: 'personas', fmt: 'entero', ancho: 12 },
      { titulo: 'Comisión', campo: 'lista', fmt: 'pesos', ancho: 16, total: 'suma' },
    ], d.atrasados, { totales: true, filtro: false })
  }

  seccion(ws, 'Por persona', 'Lo de cada persona sumando todos sus meses y tiendas. El detalle orden por orden está en la hoja "Por persona".')
  tabla(ws, [
    { titulo: 'Persona', campo: 'vendedor_nombre', ancho: 34 },
    { titulo: 'Tienda(s)', campo: p => [...new Set(p.bloques.map(b => b.tienda_nombre))].join(', '), ancho: 22 },
    { titulo: 'Meses', campo: p => p.bloques.map(b => mesEnPalabras(b.mes)).filter((m, i, a) => a.indexOf(m) === i).join(', '), ancho: 26 },
    { titulo: 'Comisión lista', campo: p => n(p.lista), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Anticipos y abonos que se descuentan', campo: p => n(p.se_descuenta), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'SE ENTREGA', campo: p => n(p.entregar), fmt: 'pesos', ancho: 16, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'Sigue debiendo de anticipos', campo: p => n(p.sigue_debiendo), fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Atrasada', campo: p => p.atrasada ? 'Sí' : 'No', ancho: 10, color: p => p.atrasada ? AMBAR : null },
  ], d.personas, { totales: true, etiquetaTotal: 'TOTAL', filtro: false })

  seccion(ws, 'Por tienda', 'Lo que se paga de cada tienda en cada mes. La cuenta del pool de cada una está en la hoja "Por tienda".')
  const porTienda = new Map()
  for (const p of d.personas) {
    for (const b of p.bloques) {
      const k = `${b.mes}_${b.tienda_id}`
      if (!porTienda.has(k)) porTienda.set(k, { mes: b.mes, tienda: b.tienda_nombre, personas: 0, ordenes: 0, lista: 0, se_descuenta: 0, entregar: 0 })
      const t = porTienda.get(k)
      t.personas++
      t.ordenes += (b.ordenes_listas ?? []).filter(o => o.forma_pago !== 'parte_pool').length
      t.lista += n(b.lista); t.se_descuenta += n(b.se_descuenta); t.entregar += n(b.entregar)
    }
  }
  const filasTienda = [...porTienda.values()].sort((a, b) => a.mes.localeCompare(b.mes) || String(a.tienda).localeCompare(String(b.tienda)))
  tabla(ws, [
    { titulo: 'Tienda', campo: 'tienda', ancho: 34 },
    { titulo: 'Mes de venta', campo: t => mesEnPalabras(t.mes), ancho: 22 },
    { titulo: 'Personas', campo: 'personas', fmt: 'entero', ancho: 12 },
    { titulo: 'Órdenes', campo: 'ordenes', fmt: 'entero', ancho: 16, total: 'suma' },
    { titulo: 'Comisión lista', campo: 'lista', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Se descuenta', campo: 'se_descuenta', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'SE ENTREGA', campo: 'entregar', fmt: 'pesos', ancho: 15, total: 'suma', color: () => VERDE_CLARO },
  ], filasTienda, { totales: true, etiquetaTotal: 'TOTAL GENERAL', filtro: false })

  seccion(ws, 'Qué hay en cada hoja')
  fichas(ws, [
    ['Por persona', 'Cada persona, mes por mes: de dónde sale lo que cobra y las órdenes que entran.'],
    ['Por tienda', 'Cada tienda: meta, lo que cuenta, el pool y cómo se reparte entre el equipo.'],
    ['Todas las órdenes', 'Una fila por orden, con filtro, para buscar o sumar a gusto.'],
    ['Cómo se calcula', 'Las reglas en palabras.'],
  ])
}

function hojaPorPersona(wb, ctx) {
  const ws = wb.addWorksheet('Por persona')
  titulo(ws, 'Por persona', 'Cada mes por separado: se paga del mes más viejo al más nuevo y cada uno descuenta su propio anticipo.')

  for (const p of ctx.datos.personas) {
    const r1 = ws.addRow([`${p.vendedor_nombre} — ${pesos(p.lista)} listo`
      + (n(p.se_descuenta) > 0 ? ` · se descuentan ${pesos(p.se_descuenta)} · se entregan ${pesos(p.entregar)}` : '')])
    r1.font = { size: 13, bold: true }
    ws.mergeCells(r1.number, 1, r1.number, 8)

    for (const b of p.bloques) {
      seccion(ws, `${b.tienda_nombre} · ${mesEnPalabras(b.mes)}${b.periodicidad === 'trimestral' ? ' (trimestral)' : ''}${b.atrasada ? ' · ATRASADA' : ''}`)
      explicar(ws, explicacionDe(p, b, ctx.tiendaDe))
      tabla(ws, columnasOrdenes(), renglonesDe(p, b), { totales: true, etiquetaTotal: `Total ${mesEnPalabras(b.mes)}`, filtro: false })
    }
    if (n(p.sigue_debiendo) > 0) {
      explicar(ws, [`• Después de este pago sigue debiendo ${pesos(p.sigue_debiendo)} de anticipos: se descuenta en el próximo.`])
    }
    ws.addRow([])
    ws.addRow([])
  }

  // El total va debajo de la columna de la comisión.
  const col = columnasOrdenes().findIndex(c => c.campo === 'comision') + 1
  const fin = ws.addRow([])
  fin.getCell(1).value = 'TOTAL GENERAL'
  fin.getCell(col).value = n(ctx.datos.lista)
  fin.getCell(col).numFmt = '"$"#,##0'
  fin.font = { bold: true, size: 12 }
  explicar(ws, [`Comisión ${pesos(ctx.datos.lista)} − anticipos y abonos ${pesos(ctx.datos.se_descuenta)} = se entregan ${pesos(ctx.datos.entregar)}.`])
}

function hojaPorTienda(wb, ctx) {
  const ws = wb.addWorksheet('Por tienda')
  ws.getColumn(1).width = 36
  ws.getColumn(2).width = 18
  titulo(ws, 'Por tienda', 'De dónde sale el pool: la meta, lo que cuenta para ella (con la mitad pagada) y cómo se reparte.')

  const d = ctx.datos
  // Cada tienda junta, sus meses en orden.
  const tiendas = [...(d.tiendas ?? [])].sort((a, b) =>
    String(a.tienda_nombre).localeCompare(String(b.tienda_nombre)) || a.mes.localeCompare(b.mes))
  for (const t of tiendas) {
    const r = R(t.mes, t.tienda_id)
    const pool = t.pool
    seccion(ws, `${t.tienda_nombre} · ${mesEnPalabras(t.mes)}`)

    if (!pool) {
      explicar(ws, [`• Esta tienda no reparte pool: cada uno cobra el ${num(r.pct_individual)}% de lo suyo (${cuentaVenta(r, 'pct_individual')}).`])
    } else if (pool.periodicidad === 'trimestral' && pool.trimestre) {
      const q = pool.trimestre
      explicar(ws, [
        `• Tienda trimestral (${q.trimestre}): se suman los tres meses (lo que cuenta − meta de cada uno) y se le resta la deuda que traía.`,
        `• Deuda del trimestre anterior: ${pesos(q.deficit_inicial)} de pool (${pesos(q.deuda_en_ventas)} en ventas). Pool del trimestre: ${pesos(q.pool_pagado)}`
          + (n(q.deficit_final) > 0 ? `; deja una deuda de ${pesos(q.deficit_final)} al siguiente.` : '.'),
      ])
      tabla(ws, [
        { titulo: 'Mes', campo: m => mesEnPalabras(m.mes), ancho: 36 },
        { titulo: 'Meta', campo: 'meta', fmt: 'pesos', ancho: 18 },
        { titulo: 'Cuenta (con la mitad pagada)', campo: 'cuenta', fmt: 'pesos', ancho: 16 },
        { titulo: 'Vendido sin la mitad (no suma)', campo: 'sin_mitad', fmt: 'pesos', ancho: 16 },
        { titulo: 'Cuenta − meta', campo: 'diferencia', fmt: 'pesos', ancho: 16, total: 'suma' },
        { titulo: 'Saldo acumulado', campo: 'saldo', fmt: 'pesos', ancho: 16 },
      ], q.meses ?? [], { totales: true, filtro: false })
    } else {
      fichas(ws, [
        ['Meta del mes', n(pool.meta), 'pesos'],
        [`Ventas que cuentan (con ${partePagada(r.meta_req_pagado_pct)})`, n(pool.ventas_cuentan), 'pesos'],
        ['Vendido que todavía no cuenta', n(pool.sin_mitad), 'pesos'],
        ['   órdenes sin la mitad', n(pool.ordenes_sin_mitad), 'entero'],
        ['¿Llegó a la meta?', pool.meta_cumplida ? 'Sí' : 'No'],
        ['Pool a repartir', n(pool.pool), 'pesos'],
      ])
      explicar(ws, [
        `• Pool = (${pesos(pool.ventas_cuentan)} − ${pesos(pool.meta)}) ${cuentaPool(r)} = ${pesos(pool.pool)}.`
          + ' "Ventas que cuentan" ya va sin la franquicia de la tarjeta y con lo que abonaron los independientes; sin restauraciones.',
        `• Lo pagado con ${mediosConFranquicia(r)} en la tienda: ${pesos(t.tarjeta)}; la franquicia (${num(r.franquicia_pct)}%) se llevó ${pesos(t.datafono)} y eso no comisiona.`,
      ])
      for (const l of pool.cuenta?.lineas ?? []) {
        explicar(ws, [`   ${l.tipo === 'bono' ? 'Bono' : 'Tramo'}: ${pesos(l.base)} → ${pesos(l.monto)}`])
      }
    }

    if (pool?.reparto?.length) {
      seccion(ws, 'Cómo se reparte', pool.reparto_modo === 'iguales'
        ? 'En partes iguales entre los del equipo.'
        : `Por días: pool × días de la persona ÷ ${pool.dias_total} días de todos.`)
      tabla(ws, [
        { titulo: 'Persona', campo: 'nombre', ancho: 36 },
        { titulo: 'Días', campo: 'dias', ancho: 18, total: 'suma' },
        { titulo: 'Su parte del pool', campo: 'parte', fmt: 'pesos', ancho: 16, total: 'suma' },
      ], pool.reparto, { totales: true, filtro: false })
      const sumaPartes = pool.reparto.reduce((s, x) => s + n(x.parte), 0)
      if (Math.abs(sumaPartes - n(pool.pool)) >= 1) {
        explicar(ws, [`• Las partes suman ${pesos(sumaPartes)} y no ${pesos(pool.pool)}: cada una se redondea a pesos por separado.`])
      }
    }
    if (pool?.reemplazos?.length) {
      explicar(ws, pool.reemplazos.map(x => x.reemplaza
        ? `• ${x.quien} cubrió a ${x.reemplaza} del ${fecha(x.desde)} al ${x.hasta ? fecha(x.hasta) : 'sin fecha de regreso'}: esos días son de ${x.quien}.`
        : `• ${x.quien} llegó el ${fecha(x.desde)} (${x.tipo === 'traslado' ? 'traslado' : 'reemplazo'}).`))
    }

    const deEsta = d.personas.flatMap(p => p.bloques.filter(b => b.mes === t.mes && b.tienda_id === t.tienda_id)
      .map(b => ({ nombre: p.vendedor_nombre, lista: n(b.lista), se_descuenta: n(b.se_descuenta), entregar: n(b.entregar) })))
    seccion(ws, 'Lo que se paga ahora de esta tienda')
    tabla(ws, [
      { titulo: 'Persona', campo: 'nombre', ancho: 36 },
      { titulo: 'Comisión lista', campo: 'lista', fmt: 'pesos', ancho: 18, total: 'suma' },
      { titulo: 'Se descuenta', campo: 'se_descuenta', fmt: 'pesos', ancho: 16, total: 'suma' },
      { titulo: 'SE ENTREGA', campo: 'entregar', fmt: 'pesos', ancho: 16, total: 'suma', color: () => VERDE_CLARO },
    ], deEsta, { totales: true, filtro: false })
    ws.addRow([])
  }

  // Los independientes: el bolsón de restauraciones de cada mes.
  const indeps = d.personas.flatMap(p => p.bloques.filter(b => b.es_independiente).map(b => ({ p, b })))
  const meses = [...new Set(indeps.map(x => x.b.mes))].sort()
  for (const mes of meses) {
    const r = R(mes)
    const del = indeps.filter(x => x.b.mes === mes)
    const bolson = del.find(x => x.b.independiente?.bolson_restauraciones?.length)?.b.independiente.bolson_restauraciones ?? []
    seccion(ws, `Independientes · ${mesEnPalabras(mes)}`,
      `Cobran el ${num(r.indep_pct_venta)}% de sus ventas${r.iva_en_venta ? ` (÷ ${divisor(r.iva_divisor)})` : ''}. Las restauraciones de todos van a un bolsón y `
      + (r.bolson_modo === 'se_reparte' ? `el ${num(r.indep_pct_bolson)}% se reparte entre ellos.` : `cada uno cobra el ${num(r.indep_pct_bolson)}% del total.`))
    if (bolson.length) {
      tabla(ws, [
        { titulo: 'Restauración en el bolsón', campo: 'referencia', ancho: 36 },
        { titulo: 'Fecha', campo: o => fecha(o.fecha), ancho: 18 },
        { titulo: 'Quién la subió', campo: 'vendedor', ancho: 24 },
        { titulo: 'Cliente', campo: o => o.cliente ?? '', ancho: 22 },
        { titulo: 'Valor (sin franquicia)', campo: 'valor', fmt: 'pesos', ancho: 16, total: 'suma' },
      ], bolson, { totales: true, etiquetaTotal: 'BOLSÓN', filtro: false })
    }
    tabla(ws, [
      { titulo: 'Independiente', campo: x => x.p.vendedor_nombre, ancho: 36 },
      { titulo: 'De sus ventas', campo: x => n(x.b.independiente?.por_pagar_ventas), fmt: 'pesos', ancho: 18, total: 'suma' },
      { titulo: 'Del bolsón', campo: x => n(x.b.independiente?.por_pagar_bolson), fmt: 'pesos', ancho: 16, total: 'suma' },
      { titulo: 'Comisión lista', campo: x => n(x.b.lista), fmt: 'pesos', ancho: 16, total: 'suma', color: () => VERDE_CLARO },
    ], del, { totales: true, filtro: false })
  }
}

function hojaTodas(wb, ctx) {
  const ws = wb.addWorksheet('Todas las órdenes')
  titulo(ws, 'Todas las órdenes que se pagan', 'Una fila por orden (y por el bolsón de los independientes). Con filtro: el total de abajo es el de Listas.')
  const filas = ctx.datos.personas.flatMap(p => p.bloques.flatMap(b => renglonesDe(p, b)))
  const header = tabla(ws, columnasOrdenes({ conPersona: true }), filas, { totales: true, etiquetaTotal: 'TOTAL GENERAL' })
  ws.views = [{ state: 'frozen', xSplit: 4, ySplit: header.number }]
}

function hojaComoSeCalcula(wb, ctx) {
  const ws = wb.addWorksheet('Cómo se calcula')
  ws.getColumn(1).width = 120
  titulo(ws, 'Cómo se calcula lo que se paga', 'Las reglas en palabras. Si una tienda o persona tiene reglas propias, su cuenta ya va con ellas.')
  const r = R(ctx.datos.mes_que_toca)
  const lineas = [
    '1. Qué entra en Listas',
    `• Una comisión está lista cuando: el cliente pagó al menos ${laParte(r.req_pagado_pct)} de la orden, ya llegó la fecha (${cuandoSePaga(r)} a la venta; en las trimestrales, después del cierre del trimestre) y, si va por pool, la tienda llegó a la meta.`,
    '• Si un mes no se pagó a tiempo, sigue aquí como ATRASADO y se paga primero.',
    '',
    '2. La tarjeta',
    `• Lo pagado con ${mediosConFranquicia(r)} no comisiona completo: la franquicia se queda con el ${num(r.franquicia_pct)}% de eso. Se resta a la base de la orden.`,
    '• Solo se resta sobre lo que de verdad se pagó con esos medios: en un pago mixto, solo ese pedazo. Lo pagado en efectivo o transferencia no se toca.',
    '',
    '3. El IVA',
    `• Ventas: se les quita el IVA (÷ ${divisor(r.iva_divisor)}). En una tienda con pool se le quita al pool, no orden por orden.`,
    `• Restauraciones: ${r.iva_en_restauracion ? `también ÷ ${divisor(r.iva_divisor)}` : 'no se les quita el IVA (son mano de obra)'}.`,
    '',
    '4. Tienda con meta (pool)',
    `• Pool = (ventas de la tienda con ${partePagada(r.meta_req_pagado_pct)} − meta) ${cuentaPool(r)}. Sin meta cumplida no hay pool.`,
    `• La mitad se mira en la TIENDA: una venta entra a la cuenta cuando su cliente pagó ${laParte(r.meta_req_pagado_pct)}, la venda quien la venda.`,
    `• El pool se parte entre el equipo ${r.reparto_pool === 'iguales' ? 'en partes iguales' : 'por días: quien cubrió a otro se queda con esos días'}. Todos cobran lo mismo por día.`,
    '• A cada uno se le paga su parte menos lo que ya se le pagó de ese pool, repartido sobre sus ventas que ya cuentan.',
    '',
    '5. Sin pool',
    `• Individual (tienda sin meta o quien no es del equipo): ventas ${cuentaVenta(r, 'pct_individual')}.`,
    `• Restauraciones: ${num(r.pct_restauracion)}% del valor, sin pool ni meta.`,
    `• Independientes: ${num(r.indep_pct_venta)}% de sus ventas y su parte del bolsón de restauraciones.`,
    '',
    '6. Anticipos y abonos',
    '• Lo que se le adelantó (anticipo fijo del mes o un abono de esta comisión) se descuenta al pagar. Si la comisión no alcanza, sigue debiendo y se descuenta en el próximo pago.',
  ]
  for (const l of lineas) {
    const row = ws.addRow([l])
    row.font = l && !l.startsWith('•') ? { size: 10, bold: true } : { size: 10 }
    row.alignment = { wrapText: true, vertical: 'top' }
  }
  const nota = ws.addRow(['Las cifras salen de la misma cuenta que muestra Comisiones → Listas y que se paga.'])
  nota.font = { italic: true, size: 9, color: { argb: GRIS_TEXTO } }
}

// ── Punto de entrada ──────────────────────────────────────────────────────────

/**
 * El libro armado, sin descargarlo.
 *
 * @param {Object} datos.porPagar   respuesta de /comisiones/por-pagar?detalle=1
 * @param {Object} datos.reglas     { 'YYYY-MM': vigentes } de cargarReglasVigentes
 * @param {string} [datos.generadoPor]
 */
export async function armarLibroListas({ porPagar, reglas = {}, generadoPor }) {
  const ExcelJS = (await import('exceljs')).default ?? (await import('exceljs'))
  const wb = new ExcelJS.Workbook()
  wb.creator = 'Decasa'
  wb.created = new Date()
  REGLAS = reglas

  const tiendas = new Map((porPagar.tiendas ?? []).map(t => [`${t.mes}_${t.tienda_id}`, t]))
  const ahora = new Date()
  const ctx = {
    datos: porPagar,
    tiendaDe: (mes, tiendaId) => tiendas.get(`${mes}_${tiendaId}`),
    generadoPor,
    generadoEl: `${fecha(ahora.toISOString())} ${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}`,
  }

  hojaResumen(wb, ctx)
  hojaPorPersona(wb, ctx)
  hojaPorTienda(wb, ctx)
  hojaTodas(wb, ctx)
  hojaComoSeCalcula(wb, ctx)
  return wb
}

/** Arma y descarga el libro de Listas. */
export async function exportarListasExcel(datos) {
  const wb = await armarLibroListas(datos)
  const buffer = await wb.xlsx.writeBuffer()
  const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  const hoy = new Date().toISOString().slice(0, 10)
  a.href = url
  a.download = `comisiones_por_pagar_${datos.porPagar.solo_mes ?? hoy}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
