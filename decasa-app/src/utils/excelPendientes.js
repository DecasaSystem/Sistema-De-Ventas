import { hoyBogota } from '@/utils/fechas'
/**
 * El Excel "Pagos pendientes" de Bonificaciones: un estado de cuenta por
 * persona (usuario, 2026-11-23: "¿y si lo tengo con meses atrasados, o con
 * algunos abonos y falta?").
 *
 * El de Listas dice lo que se paga hoy, con los anticipos y abonos restados en
 * una sola cifra. Este dice, mes por mes: cuánto le toca, cuánto ya se le dio
 * (cada abono y anticipo con su fecha), cuánto falta entregarle, desde cuándo
 * está atrasado y lo que todavía no se puede pagar, con el porqué.
 *
 * No calcula plata: todo sale de GET /comisiones/pagos-pendientes, que trae lo
 * mismo que Listas (por_pagar) y, de cada mes con algo pendiente, las tarjetas
 * del mismo resumen del mes (todavia_no). Lo que dice "falta entregar" es lo
 * que se entrega al pagar en Listas.
 */

import { AMBAR, VERDE_CLARO, ROJO_CLARO, mesEnPalabras, fecha, pesos, n, titulo, seccion, tabla, fichas, explicar, paraImprimir } from './excelComisiones'
import { explicacionDelBloque, partesDelMes, estadoDeOrden } from './explicarListas'
import { reglasPara, laParte } from './reglasComision'
import { hojasDeLosJefes, tarjetasDeListas } from './excelFormatoJefes'

let REGLAS = {}
const R = (mes, tiendaId = null, usuarioId = null) => reglasPara(REGLAS[mes] ?? null, { tiendaId, usuarioId })

// ── Las cuentas en palabras ───────────────────────────────────────────────────

/** Días entre dos fechas YYYY-MM-DD (para "lleva 34 días atrasado"). */
function diasEntre(desde, hasta) {
  if (!desde || !hasta) return 0
  const a = Date.UTC(+desde.slice(0, 4), +desde.slice(5, 7) - 1, +desde.slice(8, 10))
  const b = Date.UTC(+hasta.slice(0, 4), +hasta.slice(5, 7) - 1, +hasta.slice(8, 10))
  return Math.max(0, Math.round((b - a) / 86400000))
}

/** Lo que la tarjeta de "todavía no" necesita para usar las mismas reglas de Listas. */
function comoLibro(t, hoy) {
  const d = {
    mes: t.mes, hoy, se_cobra_el: t.se_cobra_el, llego_el_dia: t.llego_el_dia,
    periodicidad: t.periodicidad, tienda: { pool: { trimestre: t.trimestre } },
  }
  const p = { ordenes: t.ordenes ?? [], cuenta: t.cuenta, pagado: t.pagado, lista: t.lista, pendiente: t.pendiente, periodicidad: t.periodicidad, comision_mes: t.comision_mes }
  return { d, p }
}

/** Lo que espera y lo que ya no se va a pagar de una tarjeta de "todavía no". */
function partesDe(t, hoy) {
  const { d, p } = comoLibro(t, hoy)
  const { espera, noSePaga } = partesDelMes(d, p)
  return { espera, noSePaga }
}

/** Por qué no se puede pagar todavía, en una o dos frases. */
function porQueTodaviaNo(t, hoy) {
  const r = R(t.mes, t.tienda_id, t.vendedor_id)
  if (t.mes_en_curso) return `El mes todavía no termina: es lo que va hasta hoy y puede cambiar. Se paga el ${fecha(t.se_cobra_el)}.`
  if (!t.llego_el_dia) return `Todavía no llega el día de pago (${fecha(t.se_cobra_el)}). Puede cambiar si los clientes pagan lo que deben.`

  const { d, p } = comoLibro(t, hoy)
  const ordenes = (t.ordenes ?? []).filter(o => o.forma_pago !== 'parte_pool')
  const sinMitad = ordenes.filter(o => !o.req_50_pct)
  const lineas = []
  if (sinMitad.length) {
    lineas.push(`${sinMitad.length} orden(es) esperan que el cliente pague ${laParte(r.req_pagado_pct)}: ${sinMitad.map(o => o.orden_referencia ?? '#' + o.orden_numero).join(', ')}.`)
  }
  if ((t.ordenes ?? []).some(o => o.forma_pago === 'parte_pool') || ordenes.some(o => o.req_50_pct)) {
    const estados = [...new Set((t.ordenes ?? []).filter(o => o.forma_pago === 'parte_pool' || o.req_50_pct).map(o => estadoDeOrden(o, p, d, r)))]
    lineas.push(...estados.map(e => e === 'Pendiente' ? 'Espera a que la tienda llegue a la meta con lo que ya tiene la mitad pagada.' : `${e}.`))
  }
  if (t.es_independiente && !lineas.length) lineas.push('Las restauraciones del bolsón y sus ventas esperan que el cliente pague la mitad.')
  return lineas.join(' ') || 'Espera las condiciones de pago (la mitad pagada, el día de pago y la meta).'
}

/** "agosto 2026 · abono del 25/09/2026: $150.000 (nota)". */
function anticipoTexto(a) {
  return `${mesEnPalabras(a.mes)} · ${tipoAnticipo(a)}${a.fecha ? ` del ${fecha(a.fecha)}` : ''}: ${pesos(a.monto)}${a.nota ? ` (${a.nota})` : ''}`
}

function tipoAnticipo(a) {
  return a.es_cobro_de_mas ? 'Cobrado de más' : a.es_abono ? 'Abono' : 'Anticipo del mes'
}

// ── Las personas ──────────────────────────────────────────────────────────────

/** Cada persona con lo que toca (Listas) y lo que todavía no, juntos. */
function personasDe(datos) {
  const hoy = datos.hoy
  const porId = new Map()
  const de = (id, nombre) => {
    if (!porId.has(id)) porId.set(id, { vendedor_id: id, vendedor_nombre: nombre, bloques: [], todavia: [], anticipos: [], lista: 0, se_descuenta: 0, entregar: 0, sigue_debiendo: 0, atrasada: false })
    return porId.get(id)
  }

  for (const p of datos.por_pagar?.personas ?? []) {
    const x = de(p.vendedor_id, p.vendedor_nombre)
    x.fuente = p
    x.bloques = p.bloques ?? []
    x.anticipos = p.anticipos_detalle ?? []
    x.lista = n(p.lista); x.se_descuenta = n(p.se_descuenta); x.entregar = n(p.entregar)
    x.sigue_debiendo = n(p.sigue_debiendo); x.atrasada = !!p.atrasada
  }
  for (const t of datos.todavia_no ?? []) {
    const x = de(t.vendedor_id, t.vendedor_nombre)
    x.todavia.push({ ...t, ...partesDe(t, hoy) })
  }

  for (const x of porId.values()) {
    x.espera   = x.todavia.reduce((s, t) => s + n(t.espera), 0)
    x.noSePaga = x.todavia.reduce((s, t) => s + n(t.noSePaga), 0)
    x.atrasados = x.bloques.filter(b => b.atrasada)
    x.masViejo = x.atrasados.map(b => b.se_cobra_el).sort()[0] ?? null
    x.tiendas = [...new Set([...x.bloques, ...x.todavia].map(b => b.tienda_nombre).filter(Boolean))].join(', ')
  }

  // Lo atrasado arriba, después lo que más se le debe.
  return [...porId.values()]
    .filter(x => x.lista > 0 || x.espera > 0 || x.noSePaga > 0)
    .sort((a, b) => (a.atrasada === b.atrasada ? 0 : a.atrasada ? -1 : 1) || b.entregar - a.entregar || b.espera - a.espera)
}

// ── Las hojas ─────────────────────────────────────────────────────────────────

function hojaResumen(wb, ctx) {
  const { personas, datos } = ctx
  const pp = datos.por_pagar ?? {}
  const ws = wb.addWorksheet('Resumen')
  ws.getColumn(1).width = 40
  ws.getColumn(2).width = 22

  titulo(ws, ctx.para ? `Pagos pendientes de bonificaciones · ${ctx.para}` : 'Pagos pendientes de bonificaciones',
    `Estado de cuenta por persona · generado el ${ctx.generadoEl}${ctx.generadoPor ? ' por ' + ctx.generadoPor : ''}`)

  const espera   = personas.reduce((s, p) => s + p.espera, 0)
  const noSePaga = personas.reduce((s, p) => s + p.noSePaga, 0)
  fichas(ws, [
    ['Hoy', fecha(datos.hoy)],
    ['Último día de pago', fecha(pp.dia_de_pago)],
    ['Personas con algo pendiente', personas.length, 'entero'],
    ['Ya toca pagar (listo)', n(pp.lista), 'pesos'],
    ['   Ya se les dio antes (anticipos y abonos)', n(pp.se_descuenta), 'pesos'],
    ['   FALTA ENTREGAR HOY', n(pp.entregar), 'pesos'],
    ['Todavía no se puede pagar', espera, 'pesos'],
    ...(noSePaga > 0 ? [['No se va a pagar (la tienda no llegó a la meta)', noSePaga, 'pesos']] : []),
  ])
  explicar(ws, [
    '"Ya toca pagar" es lo que cumple las 3 condiciones (el cliente pagó la mitad, llegó el día de pago y la tienda llegó a la meta): es lo mismo que sale en Listas.',
    '"Ya se les dio antes" son los anticipos y abonos que se entregaron y se descuentan al pagar. "Falta entregar" es la resta: lo que se entrega el día que se paga.',
    '"Todavía no se puede pagar" es lo que espera alguna de las condiciones; está en la hoja "Todavía no", con el porqué. Lo del mes en curso es estimado.',
  ])

  if (pp.atrasados?.length) {
    seccion(ws, 'Meses atrasados', 'Su día de pago ya pasó y no se han pagado. Se pagan primero, del más viejo al más nuevo.')
    tabla(ws, [
      { titulo: 'Mes de venta', campo: a => mesEnPalabras(a.mes), ancho: 40 },
      { titulo: 'Se debía pagar el', campo: a => fecha(a.se_cobra_el), ancho: 22 },
      { titulo: 'Días de atraso', campo: a => diasEntre(a.se_cobra_el, datos.hoy), fmt: 'entero', ancho: 14 },
      { titulo: 'Personas', campo: 'personas', fmt: 'entero', ancho: 12 },
      { titulo: 'Toca pagar', campo: 'lista', fmt: 'pesos', ancho: 16, total: 'suma' },
    ], pp.atrasados, { totales: true, filtro: false })
  }

  seccion(ws, 'Por persona', 'El detalle de cada una, mes por mes, está en la hoja "Por persona".')
  tabla(ws, [
    { titulo: 'Persona', campo: 'vendedor_nombre', ancho: 40 },
    { titulo: 'Tienda(s)', campo: 'tiendas', ancho: 22 },
    { titulo: 'Meses por pagar', campo: p => [...new Set(p.bloques.map(b => mesEnPalabras(b.mes)))].join(', '), ancho: 24 },
    { titulo: 'Meses atrasados', campo: p => p.atrasados.length, fmt: 'entero', ancho: 11, color: p => p.atrasados.length ? AMBAR : null },
    { titulo: 'El más viejo se debía pagar el', campo: p => p.masViejo ? fecha(p.masViejo) : '', ancho: 15 },
    { titulo: 'Toca pagar', campo: 'lista', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Ya se le dio', campo: 'se_descuenta', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'FALTA ENTREGAR HOY', campo: 'entregar', fmt: 'pesos', ancho: 16, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'Todavía no se puede pagar', campo: 'espera', fmt: 'pesos', ancho: 16, total: 'suma' },
    ...(noSePaga > 0 ? [{ titulo: 'No se va a pagar', campo: 'noSePaga', fmt: 'pesos', ancho: 14, total: 'suma' }] : []),
    { titulo: 'Sigue debiendo de anticipos', campo: 'sigue_debiendo', fmt: 'pesos', ancho: 15, total: 'suma' },
  ], personas, { totales: true, etiquetaTotal: 'TOTAL', filtro: false })

  seccion(ws, 'Qué hay en cada hoja')
  fichas(ws, [
    ['Por persona', 'Cada persona: lo que toca mes por mes, cada abono y anticipo con su fecha, lo que falta y lo que todavía no se puede pagar.'],
    ['Todavía no', 'Todo lo que espera alguna condición, con el porqué, y las órdenes que el cliente tiene que terminar de pagar.'],
    ['Anticipos y abonos', 'Cada anticipo y abono que se va a descontar, con su fecha.'],
  ])
}

function hojaPorPersona(wb, ctx) {
  const { personas, datos } = ctx
  const ws = wb.addWorksheet('Por persona')
  titulo(ws, 'Por persona', 'Mes por mes: cuánto le toca, cuánto ya se le dio y cuánto falta. Se paga del mes más viejo al más nuevo.')

  for (const p of personas) {
    const r1 = ws.addRow([`${p.vendedor_nombre} — falta entregar ${pesos(p.entregar)}`
      + (p.espera > 0 ? ` · todavía no se puede pagar ${pesos(p.espera)}` : '')])
    r1.font = { size: 13, bold: true }
    ws.mergeCells(r1.number, 1, r1.number, 9)

    if (p.bloques.length) {
      seccion(ws, 'Lo que ya toca pagar', p.atrasados.length ? `Tiene ${p.atrasados.length} mes(es) atrasado(s).` : null)
      tabla(ws, [
        { titulo: 'Mes de venta', campo: b => mesEnPalabras(b.mes), ancho: 18 },
        { titulo: 'Tienda', campo: 'tienda_nombre', ancho: 20 },
        { titulo: 'Se debía pagar el', campo: b => fecha(b.se_cobra_el), ancho: 14 },
        { titulo: 'Días de atraso', campo: b => b.atrasada ? diasEntre(b.se_cobra_el, datos.hoy) : 0, fmt: 'entero', ancho: 10, color: b => b.atrasada ? AMBAR : null },
        { titulo: 'Bonificación del mes', campo: b => n(b.comision_mes), fmt: 'pesos', ancho: 15, total: 'suma' },
        { titulo: 'Ya pagado de ese mes', campo: b => n(b.pagado_mes), fmt: 'pesos', ancho: 15, total: 'suma' },
        { titulo: 'Toca pagar', campo: b => n(b.lista), fmt: 'pesos', ancho: 15, total: 'suma' },
        { titulo: 'Ya se le dio (anticipos y abonos)', campo: b => n(b.se_descuenta), fmt: 'pesos', ancho: 16, total: 'suma' },
        { titulo: 'FALTA ENTREGAR', campo: b => n(b.entregar), fmt: 'pesos', ancho: 15, total: 'suma', color: () => VERDE_CLARO },
      ], p.bloques, { totales: true, etiquetaTotal: 'Total', filtro: false })

      for (const b of p.bloques) {
        const r = R(b.mes, b.tienda_id, p.vendedor_id)
        const tienda = (datos.por_pagar?.tiendas ?? []).find(t => t.mes === b.mes && t.tienda_id === b.tienda_id) ?? null
        const lineas = [`${mesEnPalabras(b.mes)} · ${b.tienda_nombre}${b.atrasada ? ` · ATRASADO desde el ${fecha(b.se_cobra_el)}` : ''}:`]
        lineas.push(`   Le toca ${pesos(b.lista)}`
          + (n(b.pagado_mes) > 0 ? ` (de ese mes ya se le habían pagado ${pesos(b.pagado_mes)})` : '')
          + (n(b.se_descuenta) > 0 ? `; ya se le dio ${pesos(b.se_descuenta)}; falta entregar ${pesos(b.entregar)}.` : `; no se le ha dado nada: falta entregar ${pesos(b.entregar)}.`))
        if (n(b.abonos) > 0) lineas.push(`   De este mes se le dieron abonos por ${pesos(b.abonos)}.`)
        if (n(b.pendiente_mes) > 0) lineas.push(`   De este mes todavía hay ${pesos(b.pendiente_mes)} que no se pueden pagar (ver abajo "Todavía no se puede pagar").`)
        lineas.push(...explicacionDelBloque(p.fuente ?? p, b, r, tienda, { excel: true }).map(l => `   • ${l}`))
        explicar(ws, lineas)
      }
    }

    if (p.anticipos.length) {
      explicar(ws, ['Lo que ya se le dio y se descuenta al pagar (del más viejo al más nuevo):', ...p.anticipos.map(a => `   • ${anticipoTexto(a)}`)])
    }
    if (p.sigue_debiendo > 0) {
      explicar(ws, [`Después de pagarle todo esto sigue debiendo ${pesos(p.sigue_debiendo)} de anticipos: se descuenta en el próximo pago.`])
    }

    const espera = p.todavia.filter(t => t.espera > 0)
    if (espera.length) {
      seccion(ws, 'Todavía no se puede pagar', 'No entra en lo que se entrega hoy. Cuando cumpla las condiciones pasa a "toca pagar".')
      tabla(ws, [
        { titulo: 'Mes de venta', campo: t => mesEnPalabras(t.mes) + (t.mes_en_curso ? ' (en curso)' : ''), ancho: 18 },
        { titulo: 'Tienda', campo: 'tienda_nombre', ancho: 20 },
        { titulo: 'Se paga el', campo: t => fecha(t.se_cobra_el), ancho: 14 },
        { titulo: 'Espera', campo: 'espera', fmt: 'pesos', ancho: 15, total: 'suma', color: t => t.mes_en_curso ? AMBAR : null },
        { titulo: 'Por qué', campo: t => porQueTodaviaNo(t, datos.hoy), ancho: 70 },
      ], espera, { totales: true, etiquetaTotal: 'Total', filtro: false })
    }
    const noSePaga = p.todavia.filter(t => t.noSePaga > 0)
    if (noSePaga.length) {
      explicar(ws, noSePaga.map(t => `No se va a pagar: ${pesos(t.noSePaga)} de ${mesEnPalabras(t.mes)} en ${t.tienda_nombre}. `
        + (t.periodicidad === 'trimestral' ? 'El trimestre cerró sin pasar de la meta.' : 'La tienda no llegó a la meta y el mes ya cerró.')))
    }
    ws.addRow([])
    ws.addRow([])
  }
}

function hojaTodaviaNo(wb, ctx) {
  const { personas, datos } = ctx
  const ws = wb.addWorksheet('Todavía no')
  titulo(ws, 'Todavía no se puede pagar', `Lo que espera alguna condición, de ${mesEnPalabras(datos.desde)} a hoy. Lo del mes en curso es estimado (en ámbar).`)

  const filas = personas.flatMap(p => p.todavia.filter(t => t.espera > 0).map(t => ({ ...t, persona: p.vendedor_nombre })))
  if (!filas.length) {
    explicar(ws, ['No hay nada esperando: todo lo que hay se puede pagar o ya se pagó.'])
    return
  }
  tabla(ws, [
    { titulo: 'Persona', campo: 'persona', ancho: 24 },
    { titulo: 'Tienda', campo: 'tienda_nombre', ancho: 20 },
    { titulo: 'Mes de venta', campo: t => mesEnPalabras(t.mes) + (t.mes_en_curso ? ' (en curso)' : ''), ancho: 18 },
    { titulo: 'Se paga el', campo: t => fecha(t.se_cobra_el), ancho: 14 },
    { titulo: 'Espera', campo: 'espera', fmt: 'pesos', ancho: 15, total: 'suma', color: t => t.mes_en_curso ? AMBAR : null },
    { titulo: 'Por qué', campo: t => porQueTodaviaNo(t, datos.hoy), ancho: 70 },
  ], filas, { totales: true, etiquetaTotal: 'TOTAL' })

  // Las órdenes que el cliente tiene que terminar de pagar: para salir a cobrar.
  const porCobrar = filas.flatMap(t => {
    const r = R(t.mes, t.tienda_id, t.vendedor_id)
    return (t.ordenes ?? []).filter(o => o.forma_pago !== 'parte_pool' && !o.req_50_pct).map(o => ({
      persona: t.persona, tienda: t.tienda_nombre, mes: t.mes,
      orden: o.orden_referencia ?? (o.orden_numero ? '#' + o.orden_numero : ''),
      cliente: o.cliente_nombre, valor: n(o.valor_total_orden ?? o.valor_orden), pagado: n(o.pagado),
      falta: Math.max(0, Math.ceil(n(o.valor_total_orden ?? o.valor_orden) * n(r.req_pagado_pct) - n(o.pagado))),
      parte: laParte(r.req_pagado_pct),
      bonificacion: n(o.monto_comision),
    }))
  })
  if (porCobrar.length) {
    seccion(ws, 'Órdenes que el cliente tiene que terminar de pagar', 'Hasta que el cliente pague lo que falta, la orden no entra a la meta ni se paga su bonificación.')
    tabla(ws, [
      { titulo: 'Persona', campo: 'persona', ancho: 24 },
      { titulo: 'Tienda', campo: 'tienda', ancho: 20 },
      { titulo: 'Mes de venta', campo: o => mesEnPalabras(o.mes), ancho: 18 },
      { titulo: 'Orden', campo: 'orden', ancho: 14 },
      { titulo: 'Cliente', campo: 'cliente', ancho: 26 },
      { titulo: 'Valor de la orden', campo: 'valor', fmt: 'pesos', ancho: 15 },
      { titulo: 'Ha pagado', campo: 'pagado', fmt: 'pesos', ancho: 15 },
      { titulo: 'Le falta para llegar a la parte que se pide', campo: 'falta', fmt: 'pesos', ancho: 18, total: 'suma', color: () => ROJO_CLARO },
      { titulo: 'Bonificación que espera', campo: 'bonificacion', fmt: 'pesos', ancho: 15, total: 'suma' },
    ], porCobrar, { totales: true, etiquetaTotal: 'TOTAL' })
  }

  const noSePaga = personas.flatMap(p => p.todavia.filter(t => t.noSePaga > 0).map(t => ({ ...t, persona: p.vendedor_nombre })))
  if (noSePaga.length) {
    seccion(ws, 'No se va a pagar', 'El cliente ya pagó, pero la tienda no llegó a la meta y ese mes (o trimestre) ya cerró.')
    tabla(ws, [
      { titulo: 'Persona', campo: 'persona', ancho: 24 },
      { titulo: 'Tienda', campo: 'tienda_nombre', ancho: 20 },
      { titulo: 'Mes de venta', campo: t => mesEnPalabras(t.mes), ancho: 18 },
      { titulo: 'No se paga', campo: 'noSePaga', fmt: 'pesos', ancho: 15, total: 'suma' },
    ], noSePaga, { totales: true, etiquetaTotal: 'TOTAL', filtro: false })
  }
}

function hojaAnticipos(wb, ctx) {
  const ws = wb.addWorksheet('Anticipos y abonos')
  titulo(ws, 'Anticipos y abonos', 'Lo que ya se le dio a cada persona y se le descuenta al pagar, del más viejo al más nuevo.')
  const filas = ctx.personas.flatMap(p => p.anticipos.map(a => ({ ...a, persona: p.vendedor_nombre })))
  if (!filas.length) {
    explicar(ws, ['Nadie tiene anticipos ni abonos por descontar.'])
    return
  }
  tabla(ws, [
    { titulo: 'Persona', campo: 'persona', ancho: 26 },
    { titulo: 'De la bonificación de', campo: a => mesEnPalabras(a.mes), ancho: 20 },
    { titulo: 'Qué es', campo: a => tipoAnticipo(a), ancho: 18 },
    { titulo: 'Se dio el', campo: a => a.fecha ? fecha(a.fecha) : '', ancho: 14 },
    { titulo: 'Por descontar', campo: a => n(a.monto), fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Nota', campo: a => a.nota ?? '', ancho: 50 },
  ], filas, { totales: true, etiquetaTotal: 'TOTAL' })
  explicar(ws, [
    'Abono: una parte de una bonificación ya lista que se entregó antes de pagarla completa.',
    'Anticipo del mes: lo que se lleva por adelantado cada mes; se descuenta de la bonificación de ese mes.',
    'Cobrado de más: lo que se le pagó de una venta que después se canceló o bajó de valor.',
  ])
}

// ── Solo una tienda, un independiente o una persona ──────────────────────────
//
// Para mandárselo (usuario, 2026-11-25: "desde ahí la opción de enviarlo por
// persona o por tienda"). Es el mismo estado de cuenta, recortado: los totales
// se vuelven a sumar con lo que queda, nunca se recalcula nada aparte.
//  - tiendaId: solo los meses de esa tienda (quien cubrió en otra sale solo
//    con lo de aquí; en el Excel de la otra tienda sale lo de allá).
//  - vendedorId: solo esa persona, con todas sus tiendas (un independiente
//    también va por aquí: no es de ninguna tienda).

/** Quiénes tienen algo pendiente, para escoger a quién mandárselo. */
export function opcionesDePendientes(datos) {
  const tiendas = new Map(), independientes = new Map(), personas = new Map()
  const bloques = [
    ...(datos?.por_pagar?.personas ?? []).flatMap(p => (p.bloques ?? []).map(b => ({ ...b, vendedor_id: p.vendedor_id, vendedor_nombre: p.vendedor_nombre }))),
    ...(datos?.todavia_no ?? []),
  ]
  for (const b of bloques) {
    personas.set(b.vendedor_id, b.vendedor_nombre)
    if (b.es_independiente) independientes.set(b.vendedor_id, b.vendedor_nombre)
    else if (b.tienda_id) tiendas.set(b.tienda_id, b.tienda_nombre)
  }
  const lista = m => [...m].map(([id, nombre]) => ({ id, nombre })).sort((a, b) => String(a.nombre).localeCompare(String(b.nombre)))
  return { tiendas: lista(tiendas), independientes: lista(independientes), personas: lista(personas) }
}

export function soloDe(datos, { tiendaId = null, vendedorId = null } = {}) {
  const deLaTienda = b => tiendaId == null || (!b.es_independiente && Number(b.tienda_id) === Number(tiendaId))
  const dePersona  = id => vendedorId == null || Number(id) === Number(vendedorId)
  const suma = (xs, campo) => xs.reduce((s, x) => s + n(x[campo]), 0)

  const pp = datos.por_pagar ?? {}
  const personas = (pp.personas ?? [])
    .filter(p => dePersona(p.vendedor_id))
    .map(p => {
      const bloques = (p.bloques ?? []).filter(deLaTienda)
      return { ...p, bloques, lista: suma(bloques, 'lista'), se_descuenta: suma(bloques, 'se_descuenta'),
        entregar: suma(bloques, 'entregar'), atrasada: bloques.some(b => b.atrasada) }
    })
    .filter(p => p.bloques.length)

  // Los meses atrasados, contados otra vez con lo que quedó.
  const atrasados = new Map()
  for (const p of personas) {
    for (const b of p.bloques.filter(x => x.atrasada)) {
      const a = atrasados.get(b.mes) ?? { mes: b.mes, se_cobra_el: b.se_cobra_el, personas: new Set(), lista: 0 }
      a.personas.add(p.vendedor_id)
      a.lista += n(b.lista)
      atrasados.set(b.mes, a)
    }
  }

  return {
    ...datos,
    por_pagar: {
      ...pp,
      personas,
      lista: suma(personas, 'lista'),
      se_descuenta: suma(personas, 'se_descuenta'),
      entregar: suma(personas, 'entregar'),
      atrasados: [...atrasados.values()].sort((a, b) => a.mes.localeCompare(b.mes)).map(a => ({ ...a, personas: a.personas.size })),
    },
    todavia_no: (datos.todavia_no ?? []).filter(t => deLaTienda(t) && dePersona(t.vendedor_id)),
  }
}

// ── El libro ──────────────────────────────────────────────────────────────────

export async function armarLibroPendientes({ datos, reglas = {}, generadoPor, para = null }) {
  const ExcelJS = (await import('exceljs')).default ?? (await import('exceljs'))
  const wb = new ExcelJS.Workbook()
  wb.creator = 'Decasa'
  wb.created = new Date()
  REGLAS = reglas

  const ahora = new Date()
  const ctx = {
    datos,
    personas: personasDe(datos),
    generadoPor,
    para,
    generadoEl: `${fecha(ahora.toISOString())} ${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}`,
  }

  // Primero lo de los jefes: la plantilla que ellos usan (2026-11-25).
  const jefes = tarjetasDeListas(datos.por_pagar ?? {})
  hojasDeLosJefes(wb, {
    titulo: `PAGOS PENDIENTES AL ${fecha(datos.hoy ?? ahora.toISOString())}${para ? ` · ${String(para).toUpperCase()}` : ''}`,
    tarjetas: jefes.tarjetas, anticipos: jefes.anticipos, reglas, reglasPorMes: true,
    columnaMonto: 'LISTO PARA PAGAR', columnaTotal: 'FALTA ENTREGAR',
    notaCuadro: 'Lo listo y sin pagar de cada mes atrasado, menos los anticipos y abonos ya dados. Lo que todavía no se puede pagar está en la hoja "Todavía no".',
  })

  hojaResumen(wb, ctx)
  hojaPorPersona(wb, ctx)
  hojaTodaviaNo(wb, ctx)
  hojaAnticipos(wb, ctx)
  wb.worksheets.forEach(ws => paraImprimir(ws, { libro: 'Pagos pendientes', generadoEl: ctx.generadoEl }))
  return wb
}

/** Las personas que salen en el libro (para saber si hay algo que bajar). */
export function hayPendientes(datos) {
  return personasDe(datos).length > 0
}

export async function exportarPendientesExcel(datos) {
  const wb = await armarLibroPendientes(datos)
  const buffer = await wb.xlsx.writeBuffer()
  const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  // "Decasa Vía El Edén" → "decasa_via_el_eden"
  const quien = datos.para
    ? '_' + String(datos.para).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
    : ''
  a.download = `bonificaciones_pagos_pendientes${quien}_${hoyBogota()}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
