/**
 * El Excel de una tienda en un mes, para mandárselo a sus vendedores.
 *
 * Pedido del dueño (2026-11-05): "un Excel de agosto de El Edén y se lo envío a
 * Sebastián y Gladys, donde dice qué se les va a pagar, si ya está listo para
 * pagar y por qué: si faltaron, si los reemplazaron, cuáles no tienen el 50 %,
 * cuáles con tarjeta y el cálculo bien hecho". Lo lee el vendedor, no
 * contabilidad: cada persona tiene su hoja con lo suyo en palabras, y la hoja
 * de la tienda tiene la cuenta del pool que es de todos.
 *
 * No calcula nada de plata: todo sale de GET /comisiones/libro-tienda, que es
 * el mismo resumen del mes que pinta la pantalla y que se paga (y, para los
 * anticipos, los mismos bloques de Listas). Los textos salen de
 * utils/explicarListas (los mismos de Listas) y las columnas de cada orden de
 * utils/excelListas.
 */

import {
  FORMA, CANAL, VERDE_CLARO, GRIS_TEXTO, AMBAR,
  mesEnPalabras, fecha, pesos, n, titulo, seccion, tabla, fichas, explicar, paraImprimir,
} from './excelComisiones'
import { tipoDe, mediosTexto, porQueTarjeta, baseTexto, ivaTexto, comoSale } from './excelListas'
import { explicacionDelMes, explicacionDelIndependiente, estadoCorto, estadoDeOrden, partesDelMes } from './explicarListas'
import { reglasPara, num, divisor, cuentaPool, cuentaVenta, laParte, partePagada, mediosConFranquicia, restauracionYMeta, cuandoSePaga } from './reglasComision'

let VIGENTES = null
const diasTexto = v => Number(v).toLocaleString('es-CO', { maximumFractionDigits: 1 })
const R = (d, p = null) => reglasPara(VIGENTES, { tiendaId: d.tienda_id, usuarioId: p?.vendedor_id ?? null })

const POR_QUE_ORDEN = {
  venta_tienda: 'Venta de la tienda',
  restauracion: 'Restauración',
  de_independiente: 'La compartió un independiente',
  otra_tienda: 'Vendida por canal digital por alguien del equipo',
}

/** Qué pasa con todo el mes de la tienda, en una línea. */
function estadoDeLaTienda(d) {
  if (!d.llego_el_dia) return `Todavía no llega el día de pago (${fecha(d.se_cobra_el)}): las cifras son cómo va y pueden cambiar.`
  if (n(d.lista) > 0 && n(d.pagado) > 0) return `Una parte ya se pagó y otra está lista para pagar (día de pago: ${fecha(d.se_cobra_el)}).`
  if (n(d.lista) > 0) return `Listo para pagar. Día de pago: ${fecha(d.se_cobra_el)}${d.personas.some(p => p.pago?.atrasada) ? ' (ya pasó: está atrasado)' : ''}.`
  if (n(d.pagado) > 0 && n(d.pendiente) <= 0) return 'Ya se pagó todo lo de este mes.'
  if (n(d.comision_mes) <= 0) return 'Este mes no deja bonificación en esta tienda.'
  return `Día de pago: ${fecha(d.se_cobra_el)}. Hay bonificaciones que todavía no se pueden pagar (ver la hoja de cada persona).`
}

// ── Hoja de la tienda ─────────────────────────────────────────────────────────

function hojaTienda(wb, ctx) {
  const d = ctx.d
  const r = R(d)
  const t = d.tienda ?? {}
  const pool = t.pool ?? null
  const mes = mesEnPalabras(d.mes)
  const ws = wb.addWorksheet('La tienda')
  ws.getColumn(1).width = 38
  ws.getColumn(2).width = 20

  titulo(ws, `Bonificaciones de ${d.tienda_nombre} · ${mes}`,
    `Ventas de ${mes} · generado el ${ctx.generadoEl}${ctx.generadoPor ? ' por ' + ctx.generadoPor : ''}`)
  explicar(ws, [estadoDeLaTienda(d)])
  if (d.cerrada_en) explicar(ws, [`La tienda cerró el ${fecha(d.cerrada_en)}.`])

  seccion(ws, 'Lo que vendió la tienda')
  fichas(ws, [
    ['Vendido en el mes', n(t.vendido), 'pesos'],
    ['   Órdenes', n(t.ordenes), 'entero'],
    [`   Pagado con ${mediosConFranquicia(r)}`, n(t.tarjeta), 'pesos'],
    [`   Franquicia (${num(r.franquicia_pct)}%): no bonifica`, n(t.datafono), 'pesos'],
    ['   Ventas', n(t.por_tipo?.venta?.monto), 'pesos'],
    ['   Restauraciones', n(t.por_tipo?.restauracion?.monto), 'pesos'],
    ['   FV2', n(t.por_tipo?.fv2?.monto), 'pesos'],
  ])

  if (pool && pool.periodicidad === 'trimestral' && pool.trimestre) {
    const q = pool.trimestre
    seccion(ws, `La meta: ${d.tienda_nombre} es trimestral (${q.trimestre})`,
      'Los tres meses se juntan en un solo pool: se suma (lo que cuenta − meta) de cada mes y se le resta la deuda que traía del trimestre anterior.')
    tabla(ws, [
      { titulo: 'Mes de las ventas', campo: m => mesEnPalabras(m.mes), ancho: 38 },
      { titulo: 'Meta', campo: 'meta', fmt: 'pesos', ancho: 20 },
      { titulo: `Cuenta (con ${partePagada(r.meta_req_pagado_pct)})`, campo: 'cuenta', fmt: 'pesos', ancho: 16 },
      { titulo: 'Vendido sin la mitad (no suma)', campo: 'sin_mitad', fmt: 'pesos', ancho: 16 },
      { titulo: 'Cuenta − meta', campo: 'diferencia', fmt: 'pesos', ancho: 16, total: 'suma' },
      { titulo: 'Saldo acumulado', campo: 'saldo', fmt: 'pesos', ancho: 16 },
    ], q.meses ?? [], { totales: true, filtro: false })
    explicar(ws, [
      `Deuda del trimestre anterior: ${pesos(q.deuda_en_ventas)} en ventas. Pool del trimestre: ${pesos(q.pool_pagado)}`
        + (n(q.deficit_final) > 0 ? `; deja una deuda de ${pesos(q.deficit_final)} de pool al siguiente.` : '.')
        + (q.en_curso ? ` El trimestre todavía no termina: se liquida el ${fecha(q.se_liquida_el)}.` : ''),
    ])
  } else if (pool) {
    seccion(ws, `La meta de ${mes}`)
    fichas(ws, [
      ['Meta', n(pool.meta), 'pesos'],
      [`Ventas que cuentan (con ${partePagada(r.meta_req_pagado_pct)})`, n(pool.ventas_cuentan), 'pesos'],
      ['Vendido que todavía no cuenta (sin la mitad)', n(pool.sin_mitad), 'pesos'],
      ['   Órdenes sin la mitad', n(pool.ordenes_sin_mitad), 'entero'],
      ['¿Llegó a la meta?', pool.meta_cumplida ? 'Sí' : 'No'],
      ['Pool de la tienda', n(pool.pool), 'pesos'],
    ])
    explicar(ws, [
      pool.meta_cumplida
        ? `Pool = (${pesos(pool.ventas_cuentan)} − ${pesos(pool.meta)}) ${cuentaPool(r)} = ${pesos(pool.pool)}.`
        : `No llegó a la meta: le faltan ${pesos(Math.max(0, n(pool.meta) - n(pool.ventas_cuentan)))} de ventas que cuenten. Sin meta no hay pool.`,
      `"Ventas que cuentan" son las que el cliente ya pagó ${laParte(r.meta_req_pagado_pct)}, sin la franquicia de la tarjeta y con lo que abonaron los independientes. Las restauraciones ${restauracionYMeta(r)}.`,
    ])
    if (pool.reparto?.length) {
      seccion(ws, `Cómo se reparte el pool de ${mes}`, pool.reparto_modo === 'iguales'
        ? 'En partes iguales entre los del equipo.'
        : `Por días: pool × días de la persona ÷ ${diasTexto(pool.dias_total)} días de todos. Quien cubre a otro se queda con esos días.`)
      tabla(ws, [
        { titulo: 'Persona', campo: 'nombre', ancho: 38 },
        { titulo: 'Días en la tienda', campo: 'dias', ancho: 20, total: 'suma' },
        { titulo: 'Su parte del pool', campo: 'parte', fmt: 'pesos', ancho: 16, total: 'suma' },
      ], pool.reparto, { totales: true, filtro: false })
      if (pool.reemplazos?.length) {
        explicar(ws, pool.reemplazos.map(x => x.reemplaza
          ? `• ${x.quien} cubrió a ${x.reemplaza} del ${fecha(x.desde)} al ${x.hasta ? fecha(x.hasta) : 'sin fecha de regreso'}: esos días son de ${x.quien}.`
          : `• ${x.quien} llegó el ${fecha(x.desde)} (${x.tipo === 'traslado' ? 'traslado' : 'reemplazo'}).`))
      }
    }
  } else {
    seccion(ws, 'Sin meta')
    explicar(ws, [`${d.tienda_nombre} no tiene meta este mes: cada persona cobra el ${num(r.pct_individual)}% de lo suyo (ventas ${cuentaVenta(r, 'pct_individual')}).`])
  }

  seccion(ws, `Lo de cada persona en ${mes}`, 'El detalle y el porqué de cada uno está en su propia hoja (una pestaña por persona).')
  tabla(ws, [
    { titulo: 'Persona', campo: 'vendedor_nombre', ancho: 38 },
    { titulo: 'Días', campo: p => p.dias ?? '', ancho: 20 },
    { titulo: 'Bonificación del mes', campo: p => n(p.comision_mes), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Ya pagado', campo: p => n(p.pagado), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Listo para pagar', campo: p => n(p.lista), fmt: 'pesos', ancho: 16, total: 'suma', color: p => n(p.lista) > 0 ? VERDE_CLARO : null },
    { titulo: 'Anticipos que se descuentan', campo: p => n(p.pago?.se_descuenta), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'SE LE ENTREGA', campo: p => n(p.pago?.entregar), fmt: 'pesos', ancho: 16, total: 'suma', color: p => n(p.pago?.entregar) > 0 ? VERDE_CLARO : null },
    { titulo: d.llego_el_dia ? 'Todavía no se puede pagar' : 'Para el día de pago (cómo va)', campo: p => partesDelMes(d, p).espera, fmt: 'pesos', ancho: 16, total: 'suma', color: p => d.llego_el_dia && partesDelMes(d, p).espera > 0 ? AMBAR : null },
    { titulo: 'No se paga', campo: p => partesDelMes(d, p).noSePaga, fmt: 'pesos', ancho: 14, total: 'suma' },
    { titulo: 'Cómo va', campo: p => estadoCorto(d, p), ancho: 24 },
  ], d.personas, { totales: true, filtro: false })
  explicar(ws, ['"Bonificación del mes" = ya pagado + listo para pagar + lo que todavía no se puede pagar + lo que no se paga (la tienda no llegó a la meta). "Se le entrega" es lo listo menos los anticipos y abonos que ya se le dieron.'])

  seccion(ws, 'Qué hay en cada hoja')
  fichas(ws, [
    ...d.personas.map(p => [ctx.hojaDe.get(p.vendedor_id), `Lo de ${p.vendedor_nombre}: qué se le paga, en qué va y por qué, con sus órdenes.`]),
    ['Órdenes de la tienda', 'Todas las órdenes del mes, quién las vendió, cuánto han pagado, si cuentan para la meta y con qué pagaron.'],
    ['Por cobrar', 'Las órdenes que no cuentan porque el cliente no ha pagado la mitad, con cuánto les falta.'],
    ['Cómo se calcula', 'Las reglas en palabras.'],
  ])
}

// ── Una hoja por persona ──────────────────────────────────────────────────────

function columnasOrdenes() {
  return [
    { titulo: 'Orden', campo: 'orden', ancho: 11 },
    { titulo: 'Cliente', campo: 'cliente', ancho: 22 },
    { titulo: 'Fecha', campo: 'fecha', ancho: 11 },
    { titulo: 'Tipo', campo: 'tipo', ancho: 18 },
    { titulo: 'Cómo se paga', campo: 'como', ancho: 18 },
    { titulo: 'Valor de la orden', campo: 'valor', fmt: 'pesos', ancho: 15 },
    { titulo: 'Pagado por el cliente', campo: 'pagado', fmt: 'pesos', ancho: 15 },
    { titulo: '% pagado', campo: 'pct', fmt: 'pct', ancho: 9 },
    { titulo: 'Tiene la mitad', campo: 'cumple', ancho: 9, color: f => f.cumple === 'No' ? AMBAR : null },
    { titulo: 'Cómo pagó', campo: 'medios', ancho: 26 },
    { titulo: 'Franquicia restada', campo: 'franquicia', fmt: 'pesos', ancho: 13, total: 'suma' },
    { titulo: '¿Por qué se resta o no la tarjeta?', campo: 'por_que_tarjeta', ancho: 40 },
    { titulo: 'Base que bonifica', campo: 'base', fmt: 'pesos', ancho: 15 },
    { titulo: 'Cómo sale la base', campo: 'base_texto', ancho: 32 },
    { titulo: 'IVA (÷ 1,19)', campo: 'iva', ancho: 24 },
    { titulo: 'BONIFICACIÓN', campo: 'comision', fmt: 'pesos', ancho: 14, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'Estado', campo: 'estado', ancho: 26, color: f => f.estado_clave === 'pendiente' ? AMBAR : null },
    { titulo: 'De dónde sale', campo: 'como_sale', ancho: 56 },
  ]
}

function renglonesDe(d, p, { independiente = false } = {}) {
  const r = R(d, p)
  const b = { es_independiente: independiente, mes: d.mes, tienda_id: d.tienda_id, tienda_nombre: d.tienda_nombre }
  return (p.ordenes ?? []).map(o => {
    const sinOrden = o.forma_pago === 'parte_pool'
    return {
      orden: sinOrden ? '(sin orden)' : (o.orden_referencia ?? (o.orden_numero ? '#' + o.orden_numero : '')),
      cliente: o.cliente_nombre ?? '',
      fecha: fecha(o.fecha_venta),
      fecha_iso: String(o.fecha_venta ?? ''),
      tipo: tipoDe(o, b),
      como: FORMA[o.forma_pago] ?? 'Pool del equipo',
      valor: sinOrden ? null : n(o.valor_total_orden),
      pagado: sinOrden ? null : n(o.pagado),
      pct: sinOrden ? null : n(o.pct_pagado),
      cumple: sinOrden ? '' : (o.req_50_pct ? 'Sí' : 'No'),
      medios: mediosTexto(o),
      franquicia: sinOrden ? null : n(o.costo_datafono),
      por_que_tarjeta: porQueTarjeta(o, r),
      base: sinOrden ? null : n(o.valor_orden),
      base_texto: baseTexto(o, b, r),
      iva: ivaTexto(o, b, r),
      // La restauración de un independiente no se le paga en su renglón: va al bolsón.
      comision: independiente && o.es_restauracion ? 0 : n(o.monto_comision),
      estado: estadoDeOrden(o, p, d, r),
      estado_clave: o.estado,
      como_sale: o.forma_pago === 'fuera_del_pool' ? 'Un supervisor la sacó del pool: no suma a la meta ni paga bonificación.' : comoSale(o, b, r),
    }
  }).sort((a, b) => (a.orden === '(sin orden)') - (b.orden === '(sin orden)') || a.fecha_iso.localeCompare(b.fecha_iso))
}

function hojaPersona(wb, ctx, p) {
  const d = ctx.d
  const r = R(d, p)
  const mes = mesEnPalabras(d.mes)
  const ws = wb.addWorksheet(ctx.hojaDe.get(p.vendedor_id))
  ws.getColumn(1).width = 34
  ws.getColumn(2).width = 22

  titulo(ws, `${p.vendedor_nombre} · ${d.tienda_nombre} · ${mes}`,
    `Bonificación de las ventas de ${mes} · generado el ${ctx.generadoEl}`)

  const listo = d.llego_el_dia && n(p.lista) > 0
  const partes = partesDelMes(d, p)
  fichas(ws, [
    ['Cómo va', estadoCorto(d, p)],
    ['Día de pago', fecha(d.se_cobra_el)],
    ...(p.dias != null ? [['Días en la tienda', `${diasTexto(p.dias)} de ${d.dias_del_mes}`]] : []),
    ['Bonificación del mes', n(p.comision_mes), 'pesos'],
    ['   Ya pagado', n(p.pagado), 'pesos'],
    ['   Listo para pagar', n(p.lista), 'pesos'],
    [d.llego_el_dia ? '   Todavía no se puede pagar' : '   Para el día de pago (cómo va)', partes.espera, 'pesos'],
    ...(partes.noSePaga > 0 ? [['   No se paga (la tienda no llegó a la meta)', partes.noSePaga, 'pesos']] : []),
    ...(listo ? [
      ['Anticipos y abonos que se descuentan', n(p.pago?.se_descuenta), 'pesos'],
      ['SE LE ENTREGA', n(p.pago?.entregar ?? p.lista), 'pesos'],
    ] : []),
  ])
  if (listo) {
    const fila = ws.lastRow
    fila.font = { bold: true, size: 12 }
    fila.getCell(2).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: VERDE_CLARO } }
  }

  seccion(ws, 'Por qué')
  explicar(ws, explicacionDelMes(d, p, r).map(l => l.startsWith('   ') ? l : `• ${l}`))

  if ((p.anticipos_detalle ?? []).length) {
    seccion(ws, 'Anticipos y abonos que se le descuentan', 'Ya se le dieron; se descuentan del más viejo al más nuevo.')
    explicar(ws, p.anticipos_detalle.map(a => `• ${mesEnPalabras(a.mes)} · ${a.es_abono ? `abono del ${fecha(a.fecha)}` : 'anticipo del mes'}: ${pesos(a.monto)}${a.nota ? ` (${a.nota})` : ''}`))
  }

  const filas = renglonesDe(d, p)
  seccion(ws, `Sus órdenes de ${mes}`, filas.some(f => f.orden === '(sin orden)')
    ? '"(sin orden)" es su parte del pool cuando no tiene ventas propias que cuenten: se le paga entera en ese renglón.'
    : !p.cuenta?.por_pool ? 'Aquí no hay pool: la bonificación de cada orden es la que se le paga.'
    : 'En una tienda con pool, la bonificación de cada orden es cómo se reparte su parte del pool sobre sus ventas que ya cuentan; la cuenta real es la del pool' + (ctx.persona ? ' (arriba, en "Por qué").' : ' (hoja "La tienda").'))
  if (filas.length) {
    tabla(ws, columnasOrdenes(), filas, { totales: true, etiquetaTotal: 'TOTAL', filtro: false })
  } else {
    ws.addRow(['No tiene órdenes este mes en esta tienda.'])
  }
}

// ── Órdenes de la tienda y por cobrar ─────────────────────────────────────────

/** Una orden por fila, con lo que se sabe de cada una en las tarjetas de las personas. */
function ordenesDeLaTienda(d) {
  const deTarjetas = new Map()
  for (const p of d.personas) {
    for (const o of p.ordenes ?? []) {
      if (o.orden_id && !deTarjetas.has(o.orden_id)) deTarjetas.set(o.orden_id, o)
    }
  }
  return (d.tienda?.detalle ?? []).map(x => ({ ...x, o: deTarjetas.get(x.orden_id) ?? {} }))
}

function hojaOrdenes(wb, ctx) {
  const d = ctx.d
  const r = R(d)
  const ws = wb.addWorksheet('Órdenes de la tienda')
  titulo(ws, `Órdenes de ${d.tienda_nombre} · ${mesEnPalabras(d.mes)}`,
    `Todas las del mes. "Cuenta para la meta" = el cliente ya pagó ${laParte(r.meta_req_pagado_pct)}. "Lo de la tienda" es la parte que es de esta tienda (una compartida con un independiente, la mitad).`)
  const filas = ordenesDeLaTienda(d).map(x => ({
    orden: x.referencia ?? '',
    cliente: x.cliente ?? '',
    fecha: fecha(x.fecha),
    vendio: (x.vendedores ?? []).join(', '),
    tipo: x.tipo === 'restauracion' ? 'Restauración' : x.tipo === 'fv2' ? 'FV2' : 'Venta',
    canal: CANAL[x.canal] ?? (x.canal ?? ''),
    por_que: POR_QUE_ORDEN[x.por_que] ?? x.por_que ?? '',
    valor: n(x.valor_orden),
    de_tienda: n(x.valor_real),
    pagado: n(x.o.pagado),
    pct: n(x.pct_pagado),
    cuenta: x.cuenta ? 'Sí' : 'No',
    medios: x.o.forma_pago ? mediosTexto(x.o) : '',
    franquicia: n(x.o.costo_datafono),
  }))
  if (!filas.length) { ws.addRow(['No hay órdenes este mes.']); return }
  const header = tabla(ws, [
    { titulo: 'Orden', campo: 'orden', ancho: 11 },
    { titulo: 'Cliente', campo: 'cliente', ancho: 24 },
    { titulo: 'Fecha', campo: 'fecha', ancho: 11 },
    { titulo: 'Quién la vendió', campo: 'vendio', ancho: 24 },
    { titulo: 'Tipo', campo: 'tipo', ancho: 12 },
    { titulo: 'Canal', campo: 'canal', ancho: 11 },
    { titulo: 'Por qué está aquí', campo: 'por_que', ancho: 24 },
    { titulo: 'Valor de la orden', campo: 'valor', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Lo de la tienda', campo: 'de_tienda', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Pagado por el cliente', campo: 'pagado', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: '% pagado', campo: 'pct', fmt: 'pct', ancho: 9 },
    { titulo: 'Cuenta para la meta', campo: 'cuenta', ancho: 10, color: f => f.cuenta === 'No' ? AMBAR : null },
    { titulo: 'Cómo pagó', campo: 'medios', ancho: 30 },
    { titulo: `Franquicia (${num(r.franquicia_pct)}%) restada`, campo: 'franquicia', fmt: 'pesos', ancho: 13, total: 'suma' },
  ], filas, { totales: true, etiquetaTotal: `TOTAL (${filas.length})` })
  ws.views = [{ state: 'frozen', xSplit: 1, ySplit: header.number }]
}

function hojaPorCobrar(wb, ctx) {
  const d = ctx.d
  const r = R(d)
  const ws = wb.addWorksheet('Por cobrar')
  titulo(ws, 'Por cobrar', `Órdenes del mes que no cuentan porque el cliente no ha pagado ${laParte(r.req_pagado_pct)}. Cuando las paguen, suman y bonifican en el próximo pago.`)
  const filas = ordenesDeLaTienda(d).filter(x => !x.cuenta).map(x => ({
    orden: x.referencia ?? '',
    cliente: x.cliente ?? '',
    vendio: (x.vendedores ?? []).join(', '),
    valor: n(x.valor_orden),
    pagado: n(x.o.pagado ?? Math.round(n(x.valor_orden) * n(x.pct_pagado) / 100)),
  })).map(f => ({ ...f, falta: Math.max(0, Math.ceil(f.valor * n(r.req_pagado_pct) - f.pagado)) }))
    .sort((a, b) => a.falta - b.falta)
  if (!filas.length) { ws.addRow([`Todas las órdenes del mes ya tienen ${laParte(r.req_pagado_pct)} pagada.`]); return }
  tabla(ws, [
    { titulo: 'Orden', campo: 'orden', ancho: 11 },
    { titulo: 'Cliente', campo: 'cliente', ancho: 28 },
    { titulo: 'Quién la vendió', campo: 'vendio', ancho: 26 },
    { titulo: 'Valor', campo: 'valor', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Ya pagó', campo: 'pagado', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'FALTA PARA LA MITAD', campo: 'falta', fmt: 'pesos', ancho: 16, total: 'suma', color: () => AMBAR },
  ], filas, { totales: true, etiquetaTotal: `TOTAL (${filas.length})`, filtro: false })
}

function hojaComoSeCalcula(wb, ctx) {
  const d = ctx.d
  const r = R(d)
  const ws = wb.addWorksheet('Cómo se calcula')
  ws.getColumn(1).width = 120
  titulo(ws, 'Cómo se calcula', `Las reglas que rigen en ${mesEnPalabras(d.mes)} para ${d.tienda_nombre}.`)
  const lineas = [
    '1. Cuándo se paga una bonificación (las tres condiciones)',
    `• El cliente pagó al menos ${laParte(r.req_pagado_pct)} de la orden.`,
    `• Llegó el día de pago: ${cuandoSePaga(r)} a la venta${d.periodicidad === 'trimestral' ? ' (en una tienda trimestral, después del cierre del trimestre)' : ''}.`,
    `• La tienda llegó a la meta, medida solo con las ventas que ya tienen ${laParte(r.meta_req_pagado_pct)} pagado.`,
    '',
    '2. La tarjeta',
    `• Lo pagado con ${mediosConFranquicia(r)} no bonifica completo: la franquicia se queda con el ${num(r.franquicia_pct)}% de eso y se le resta a la orden. Lo pagado en efectivo o transferencia no se toca.`,
    '',
    '3. El IVA',
    `• A las ventas se les quita el IVA (÷ ${divisor(r.iva_divisor)}): en una tienda con pool se le quita al pool, no orden por orden.`,
    `• A las restauraciones ${r.iva_en_restauracion ? `también (÷ ${divisor(r.iva_divisor)})` : 'no (son mano de obra)'}.`,
    '',
    '4. El pool',
    `• Pool = (ventas de la tienda con ${partePagada(r.meta_req_pagado_pct)} − meta) ${cuentaPool(r)}. Sin meta cumplida no hay pool.`,
    `• Se parte entre el equipo ${r.reparto_pool === 'iguales' ? 'en partes iguales' : 'por días: cada uno se lleva la parte de los días que estuvo; quien cubre a otro se queda con esos días'}.`,
    '',
    '5. Lo demás',
    `• Restauraciones: ${num(r.pct_restauracion)}% de su valor; ${restauracionYMeta(r)}.`,
    `• Quien no es del equipo o una tienda sin meta: ventas ${cuentaVenta(r, 'pct_individual')}.`,
    `• Lo que comparte un independiente con la tienda: la tienda cobra su ${num(r.almacen_pct)}% y se parte entre los que estaban ese día.`,
    '',
    '6. Anticipos y abonos',
    '• Lo que se le adelantó se descuenta al pagar, del más viejo al más nuevo. La bonificación no cambia; cambia lo que se entrega.',
  ]
  for (const l of lineas) {
    const row = ws.addRow([l])
    row.font = l && !l.startsWith('•') ? { size: 10, bold: true } : { size: 10 }
    row.alignment = { wrapText: true, vertical: 'top' }
  }
  const nota = ws.addRow(['Las cifras salen de la misma cuenta que muestra Bonificaciones y que se paga.'])
  nota.font = { italic: true, size: 9, color: { argb: GRIS_TEXTO } }
}

// ── Una persona, con todas las tiendas donde estuvo ───────────────────────────
//
// Quien cubrió media mes en otra tienda salía en dos archivos (dueño,
// 2026-11-05). Este libro junta lo suyo: un resumen con el total y una hoja por
// tienda, la misma que sale en el Excel de esa tienda (donde sigue saliendo).

function hojaResumenPersona(wb, ctx) {
  const L = ctx.d
  const mes = mesEnPalabras(L.mes)
  const ws = wb.addWorksheet('Resumen')
  ws.getColumn(1).width = 34
  ws.getColumn(2).width = 18
  titulo(ws, `Bonificaciones de ${L.vendedor_nombre} · ${mes}`,
    `Ventas de ${mes} · generado el ${ctx.generadoEl}${ctx.generadoPor ? ' por ' + ctx.generadoPor : ''}`)
  if (!L.tiendas.length) { ws.addRow(['No tiene bonificaciones este mes.']); return }

  const filas = L.tiendas.map(t => ({ t, p: t.personas[0] }))
  explicar(ws, [filas.length > 1
    ? `• Este mes estuvo en ${filas.length} tiendas: ${filas.map(x => x.t.tienda_nombre).join(' y ')}. Cada tienda tiene su propia cuenta (meta y pool, o lo de cada uno si no reparte), así que cobra en cada una lo que le toca allá${filas.some(x => x.p.cuenta?.por_pool) ? ' (en el pool, por los días que estuvo ahí)' : ''}. Nada se paga dos veces. Abajo, una hoja por tienda con el porqué.`
    : `• Este mes estuvo en ${filas[0].t.tienda_nombre}. El porqué está en la hoja de la tienda.`])
  tabla(ws, [
    { titulo: 'Tienda', campo: x => x.t.tienda_nombre, ancho: 34 },
    { titulo: 'Días', campo: x => x.p.dias ?? '', ancho: 18 },
    { titulo: 'Bonificación del mes', campo: x => n(x.p.comision_mes), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Ya pagado', campo: x => n(x.p.pagado), fmt: 'pesos', ancho: 14, total: 'suma' },
    { titulo: 'Listo para pagar', campo: x => n(x.p.lista), fmt: 'pesos', ancho: 16, total: 'suma', color: x => n(x.p.lista) > 0 ? VERDE_CLARO : null },
    { titulo: 'Anticipos que se descuentan', campo: x => n(x.p.pago?.se_descuenta), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'SE LE ENTREGA', campo: x => n(x.p.pago?.entregar), fmt: 'pesos', ancho: 16, total: 'suma', color: x => n(x.p.pago?.entregar) > 0 ? VERDE_CLARO : null },
    { titulo: 'Todavía no se puede pagar', campo: x => partesDelMes(x.t, x.p).espera, fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'No se paga', campo: x => partesDelMes(x.t, x.p).noSePaga, fmt: 'pesos', ancho: 14, total: 'suma' },
    { titulo: 'Cómo va', campo: x => estadoCorto(x.t, x.p), ancho: 24 },
  ], filas, { totales: true, filtro: false })
  if (n(L.entregar) > 0) {
    explicar(ws, [`• En total se le entregan ${pesos(L.entregar)}${n(L.se_descuenta) > 0 ? ` (bonificación lista ${pesos(L.lista)} − anticipos y abonos ${pesos(L.se_descuenta)})` : ''}.`])
  }
}

// ── Un independiente (no es de ninguna tienda) ────────────────────────────────

function hojaIndependiente(wb, ctx) {
  const d = ctx.d
  const r = reglasPara(VIGENTES, { usuarioId: d.vendedor_id })
  const mes = mesEnPalabras(d.mes)
  const ws = wb.addWorksheet(String(d.vendedor_nombre ?? 'Independiente').replace(/[[\]:*?/\\.]/g, '').slice(0, 28) || 'Independiente')
  ws.getColumn(1).width = 34
  ws.getColumn(2).width = 22

  titulo(ws, `${d.vendedor_nombre} · independiente · ${mes}`,
    `Bonificación de las ventas y restauraciones de ${mes} · generado el ${ctx.generadoEl}${ctx.generadoPor ? ' por ' + ctx.generadoPor : ''}`)

  const listo = d.llego_el_dia && n(d.lista) > 0
  fichas(ws, [
    ['Cómo va', estadoCorto(d, d)],
    ['Día de pago', fecha(d.se_cobra_el)],
    ['Vendió en el mes', n(d.vendio), 'pesos'],
    ['Bonificación del mes', n(d.comision_mes), 'pesos'],
    ['   De sus ventas', n(d.de_sus_ventas), 'pesos'],
    ['   Del bolsón de restauraciones', n(d.del_bolson), 'pesos'],
    ['Ya pagado', n(d.pagado), 'pesos'],
    ['Listo para pagar', n(d.lista), 'pesos'],
    [d.llego_el_dia ? 'Todavía no se puede pagar' : 'Para el día de pago (cómo va)', n(d.pendiente), 'pesos'],
    ...(listo ? [
      ['Anticipos y abonos que se descuentan', n(d.pago?.se_descuenta), 'pesos'],
      ['SE LE ENTREGA', n(d.pago?.entregar ?? d.lista), 'pesos'],
    ] : []),
  ])
  if (listo) {
    const fila = ws.lastRow
    fila.font = { bold: true, size: 12 }
    fila.getCell(2).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: VERDE_CLARO } }
  }

  seccion(ws, 'Por qué')
  explicar(ws, explicacionDelIndependiente(d, r).map(l => l.startsWith('   ') ? l : `• ${l}`))

  if ((d.anticipos_detalle ?? []).length) {
    seccion(ws, 'Anticipos y abonos que se le descuentan', 'Ya se le dieron; se descuentan del más viejo al más nuevo.')
    explicar(ws, d.anticipos_detalle.map(a => `• ${mesEnPalabras(a.mes)} · ${a.es_abono ? `abono del ${fecha(a.fecha)}` : 'anticipo del mes'}: ${pesos(a.monto)}${a.nota ? ` (${a.nota})` : ''}`))
  }

  const filas = renglonesDe(d, d, { independiente: true })
  seccion(ws, `Sus órdenes de ${mes}`, 'Sus restauraciones salen con $0 aquí: no se le pagan una por una, entran al bolsón (hoja "Bolsón").')
  if (filas.length) {
    tabla(ws, columnasOrdenes(), filas, { totales: true, etiquetaTotal: 'TOTAL DE SUS VENTAS', filtro: false })
  } else {
    ws.addRow(['No tiene órdenes propias este mes.'])
  }
}

function hojaBolson(wb, ctx) {
  const d = ctx.d
  const r = reglasPara(VIGENTES, { usuarioId: d.vendedor_id })
  const b = d.bolson ?? {}
  const mes = mesEnPalabras(d.mes)
  const ws = wb.addWorksheet('Bolsón')
  titulo(ws, `Bolsón de restauraciones · ${mes}`,
    `Todas las restauraciones de los independientes${r.bolson_incluye_almacenes ? ' y de los almacenes' : ''} del mes, la suba quien la suba. "Entra" = el cliente ya pagó ${laParte(r.req_pagado_pct)} y llegó el día de pago.`)
  fichas(ws, [
    ['Bolsón del mes', n(b.total), 'pesos'],
    ['   Lo que ya entra', n(b.listo), 'pesos'],
    [r.bolson_modo === 'se_reparte' ? `Su parte (${num(r.indep_pct_bolson)}% repartido)` : `Su ${num(r.indep_pct_bolson)}% del bolsón`, n(d.del_bolson), 'pesos'],
    ['   De lo que ya entra', n(d.del_bolson_listo), 'pesos'],
  ])
  const filas = (b.restauraciones ?? []).map(o => ({
    orden: o.referencia ?? '',
    cliente: o.cliente ?? '',
    fecha: fecha(o.fecha),
    quien: o.de_almacen ? `${o.vendedor} (${o.de_almacen})` : o.vendedor,
    valor: n(o.valor),
    pagado: n(o.pagado),
    entra: o.lista ? 'Sí' : 'No',
    falta: o.lista ? null : Math.max(0, Math.ceil(n(o.valor_orden) * n(r.req_pagado_pct) - n(o.pagado))),
  }))
  if (!filas.length) { ws.addRow(['No hubo restauraciones este mes.']); return }
  seccion(ws, 'Las restauraciones del bolsón')
  tabla(ws, [
    { titulo: 'Orden', campo: 'orden', ancho: 11 },
    { titulo: 'Cliente', campo: 'cliente', ancho: 26 },
    { titulo: 'Fecha', campo: 'fecha', ancho: 11 },
    { titulo: 'Quién la subió', campo: 'quien', ancho: 24 },
    { titulo: 'Valor (sin franquicia)', campo: 'valor', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Pagado por el cliente', campo: 'pagado', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Entra', campo: 'entra', ancho: 8, color: f => f.entra === 'No' ? AMBAR : null },
    { titulo: 'Le falta para entrar', campo: 'falta', fmt: 'pesos', ancho: 16, total: 'suma' },
  ], filas, { totales: true, etiquetaTotal: `BOLSÓN (${filas.length})`, filtro: false })
}

function hojaComoSeCalculaIndependiente(wb, ctx) {
  const d = ctx.d
  const r = reglasPara(VIGENTES, { usuarioId: d.vendedor_id })
  const ws = wb.addWorksheet('Cómo se calcula')
  ws.getColumn(1).width = 120
  titulo(ws, 'Cómo se calcula (independientes)', `Las reglas que rigen en ${mesEnPalabras(d.mes)}.`)
  const lineas = [
    '1. Sus ventas',
    `• Cobra el ${num(r.indep_pct_venta)}% de cada venta suya (${cuentaVenta(r, 'indep_pct_venta')}), sin meta ni pool.`,
    `• Lo pagado con ${mediosConFranquicia(r)} no bonifica completo: la franquicia se queda con el ${num(r.franquicia_pct)}% y se le resta a la orden.`,
    '',
    '2. El bolsón de restauraciones',
    `• Se juntan todas las restauraciones del mes de los independientes${r.bolson_incluye_almacenes ? ' y de los almacenes' : ''}.`,
    r.bolson_modo === 'se_reparte'
      ? `• El ${num(r.indep_pct_bolson)}% del bolsón, sin quitarle el IVA, se reparte entre los independientes.`
      : `• Cada independiente cobra el ${num(r.indep_pct_bolson)}% del bolsón completo, sin quitarle el IVA (es mano de obra).`,
    '',
    '3. Cuándo se paga',
    `• Cuando el cliente pagó al menos ${laParte(r.req_pagado_pct)} de la orden y llegó el día de pago (${cuandoSePaga(r)} a la venta). Lo que falte entra en un pago siguiente cuando el cliente pague.`,
    '',
    '4. Con un almacén',
    `• Si comparte una venta con una tienda, él cobra lo suyo igual y la tienda cobra su propio ${num(r.almacen_pct)}% aparte.`,
    '',
    '5. Anticipos y abonos',
    '• Lo que se le adelantó se descuenta al pagar, del más viejo al más nuevo. La bonificación no cambia; cambia lo que se entrega.',
  ]
  for (const l of lineas) {
    const row = ws.addRow([l])
    row.font = l && !l.startsWith('•') ? { size: 10, bold: true } : { size: 10 }
    row.alignment = { wrapText: true, vertical: 'top' }
  }
}

// ── Punto de entrada ──────────────────────────────────────────────────────────

/** Nombres de hoja únicos y válidos para Excel (31 letras, sin []:*?/\). */
function nombresDeHoja(personas) {
  const usados = new Set(['La tienda', 'Órdenes de la tienda', 'Por cobrar', 'Cómo se calcula'].map(s => s.toLowerCase()))
  const out = new Map()
  for (const p of personas) {
    const limpio = String(p.vendedor_nombre ?? 'Persona').replace(/[[\]:*?/\\.]/g, '').trim()
    let nombre = (limpio.split(/\s+/)[0] || 'Persona').slice(0, 28)
    if (usados.has(nombre.toLowerCase())) nombre = limpio.slice(0, 28)
    let k = 2
    const base = nombre
    while (usados.has(nombre.toLowerCase())) nombre = `${base.slice(0, 26)} ${k++}`
    usados.add(nombre.toLowerCase())
    out.set(p.vendedor_id, nombre)
  }
  return out
}

/**
 * El libro armado, sin descargarlo.
 *
 * @param {Object} datos.libro   respuesta de /comisiones/libro-tienda
 * @param {Object} datos.reglas  vigentes del mes (cargarReglasVigentes)
 */
export async function armarLibroTienda({ libro, reglas = null, generadoPor }) {
  const ExcelJS = (await import('exceljs')).default ?? (await import('exceljs'))
  const wb = new ExcelJS.Workbook()
  wb.creator = 'Decasa'
  wb.created = new Date()
  VIGENTES = reglas

  const ahora = new Date()
  const ctx = {
    d: libro,
    hojaDe: nombresDeHoja(libro.personas ?? []),
    generadoPor,
    generadoEl: `${fecha(ahora.toISOString())} ${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}`,
  }

  if (libro.es_persona) {
    hojaResumenPersona(wb, ctx)
    const usadas = new Set(['resumen', 'cómo se calcula'])
    for (const t of libro.tiendas) {
      let hoja = String(t.tienda_nombre).replace(/^Decasa\s+/i, '').replace(/[[\]:*?/\\.]/g, '').slice(0, 28) || 'Tienda'
      while (usadas.has(hoja.toLowerCase())) hoja = hoja.slice(0, 26) + ' 2'
      usadas.add(hoja.toLowerCase())
      const p = t.personas[0]
      hojaPersona(wb, { ...ctx, d: t, hojaDe: new Map([[p.vendedor_id, hoja]]), persona: true }, p)
    }
    if (libro.tiendas.length) hojaComoSeCalcula(wb, { ...ctx, d: libro.tiendas[0] })
  } else if (libro.es_independiente) {
    hojaIndependiente(wb, ctx)
    hojaBolson(wb, ctx)
    hojaComoSeCalculaIndependiente(wb, ctx)
  } else {
    hojaTienda(wb, ctx)
    for (const p of libro.personas ?? []) hojaPersona(wb, ctx, p)
    hojaOrdenes(wb, ctx)
    hojaPorCobrar(wb, ctx)
    hojaComoSeCalcula(wb, ctx)
  }
  const nombre = `Bonificaciones ${libro.es_independiente || libro.es_persona ? libro.vendedor_nombre : libro.tienda_nombre} ${mesEnPalabras(libro.mes)}`
  wb.worksheets.forEach(ws => paraImprimir(ws, { libro: nombre, generadoEl: ctx.generadoEl }))
  return wb
}

/** Arma y descarga el libro de la tienda. */
export async function exportarTiendaExcel(datos) {
  const wb = await armarLibroTienda(datos)
  const buffer = await wb.xlsx.writeBuffer()
  const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  const tienda = String((datos.libro.es_independiente || datos.libro.es_persona ? datos.libro.vendedor_nombre : datos.libro.tienda_nombre) ?? 'tienda').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
  a.href = url
  a.download = `bonificaciones_${tienda}_${datos.libro.mes}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
