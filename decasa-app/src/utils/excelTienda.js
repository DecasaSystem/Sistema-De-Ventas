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
import { explicacionDelMes, estadoCorto, estadoDeOrden, partesDelMes } from './explicarListas'
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
  if (n(d.comision_mes) <= 0) return 'Este mes no deja comisión en esta tienda.'
  return `Día de pago: ${fecha(d.se_cobra_el)}. Hay comisiones que todavía no se pueden pagar (ver la hoja de cada persona).`
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

  titulo(ws, `Comisiones de ${d.tienda_nombre} · ${mes}`,
    `Ventas de ${mes} · generado el ${ctx.generadoEl}${ctx.generadoPor ? ' por ' + ctx.generadoPor : ''}`)
  explicar(ws, [estadoDeLaTienda(d)])
  if (d.cerrada_en) explicar(ws, [`La tienda cerró el ${fecha(d.cerrada_en)}.`])

  seccion(ws, 'Lo que vendió la tienda')
  fichas(ws, [
    ['Vendido en el mes', n(t.vendido), 'pesos'],
    ['   Órdenes', n(t.ordenes), 'entero'],
    [`   Pagado con ${mediosConFranquicia(r)}`, n(t.tarjeta), 'pesos'],
    [`   Franquicia (${num(r.franquicia_pct)}%): no comisiona`, n(t.datafono), 'pesos'],
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
    { titulo: 'Comisión del mes', campo: p => n(p.comision_mes), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Ya pagado', campo: p => n(p.pagado), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Listo para pagar', campo: p => n(p.lista), fmt: 'pesos', ancho: 16, total: 'suma', color: p => n(p.lista) > 0 ? VERDE_CLARO : null },
    { titulo: 'Anticipos que se descuentan', campo: p => n(p.pago?.se_descuenta), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'SE LE ENTREGA', campo: p => n(p.pago?.entregar), fmt: 'pesos', ancho: 16, total: 'suma', color: p => n(p.pago?.entregar) > 0 ? VERDE_CLARO : null },
    { titulo: d.llego_el_dia ? 'Todavía no se puede pagar' : 'Para el día de pago (cómo va)', campo: p => partesDelMes(d, p).espera, fmt: 'pesos', ancho: 16, total: 'suma', color: p => d.llego_el_dia && partesDelMes(d, p).espera > 0 ? AMBAR : null },
    { titulo: 'No se paga', campo: p => partesDelMes(d, p).noSePaga, fmt: 'pesos', ancho: 14, total: 'suma' },
    { titulo: 'Cómo va', campo: p => estadoCorto(d, p), ancho: 24 },
  ], d.personas, { totales: true, filtro: false })
  explicar(ws, ['"Comisión del mes" = ya pagado + listo para pagar + lo que todavía no se puede pagar + lo que no se paga (la tienda no llegó a la meta). "Se le entrega" es lo listo menos los anticipos y abonos que ya se le dieron.'])

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
    { titulo: 'Base que comisiona', campo: 'base', fmt: 'pesos', ancho: 15 },
    { titulo: 'Cómo sale la base', campo: 'base_texto', ancho: 32 },
    { titulo: 'IVA (÷ 1,19)', campo: 'iva', ancho: 24 },
    { titulo: 'COMISIÓN', campo: 'comision', fmt: 'pesos', ancho: 14, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'Estado', campo: 'estado', ancho: 26, color: f => f.estado_clave === 'pendiente' ? AMBAR : null },
    { titulo: 'De dónde sale', campo: 'como_sale', ancho: 56 },
  ]
}

function renglonesDe(d, p) {
  const r = R(d, p)
  const b = { es_independiente: false, mes: d.mes, tienda_id: d.tienda_id, tienda_nombre: d.tienda_nombre }
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
      comision: n(o.monto_comision),
      estado: estadoDeOrden(o, p, d, r),
      estado_clave: o.estado,
      como_sale: o.forma_pago === 'fuera_del_pool' ? 'Un supervisor la sacó del pool: no suma a la meta ni paga comisión.' : comoSale(o, b, r),
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
    `Comisión de las ventas de ${mes} · generado el ${ctx.generadoEl}`)

  const listo = d.llego_el_dia && n(p.lista) > 0
  const partes = partesDelMes(d, p)
  fichas(ws, [
    ['Cómo va', estadoCorto(d, p)],
    ['Día de pago', fecha(d.se_cobra_el)],
    ...(p.dias != null ? [['Días en la tienda', `${diasTexto(p.dias)} de ${d.dias_del_mes}`]] : []),
    ['Comisión del mes', n(p.comision_mes), 'pesos'],
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
    : 'En una tienda con pool, la comisión de cada orden es cómo se reparte su parte del pool sobre sus ventas que ya cuentan; la cuenta real es la del pool (hoja "La tienda").')
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
  titulo(ws, 'Por cobrar', `Órdenes del mes que no cuentan porque el cliente no ha pagado ${laParte(r.req_pagado_pct)}. Cuando las paguen, suman y comisionan en el próximo pago.`)
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
    '1. Cuándo se paga una comisión (las tres condiciones)',
    `• El cliente pagó al menos ${laParte(r.req_pagado_pct)} de la orden.`,
    `• Llegó el día de pago: ${cuandoSePaga(r)} a la venta${d.periodicidad === 'trimestral' ? ' (en una tienda trimestral, después del cierre del trimestre)' : ''}.`,
    `• La tienda llegó a la meta, medida solo con las ventas que ya tienen ${laParte(r.meta_req_pagado_pct)} pagado.`,
    '',
    '2. La tarjeta',
    `• Lo pagado con ${mediosConFranquicia(r)} no comisiona completo: la franquicia se queda con el ${num(r.franquicia_pct)}% de eso y se le resta a la orden. Lo pagado en efectivo o transferencia no se toca.`,
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
    '• Lo que se le adelantó se descuenta al pagar, del más viejo al más nuevo. La comisión no cambia; cambia lo que se entrega.',
  ]
  for (const l of lineas) {
    const row = ws.addRow([l])
    row.font = l && !l.startsWith('•') ? { size: 10, bold: true } : { size: 10 }
    row.alignment = { wrapText: true, vertical: 'top' }
  }
  const nota = ws.addRow(['Las cifras salen de la misma cuenta que muestra Comisiones y que se paga.'])
  nota.font = { italic: true, size: 9, color: { argb: GRIS_TEXTO } }
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

  hojaTienda(wb, ctx)
  for (const p of libro.personas ?? []) hojaPersona(wb, ctx, p)
  hojaOrdenes(wb, ctx)
  hojaPorCobrar(wb, ctx)
  hojaComoSeCalcula(wb, ctx)
  const nombre = `Comisiones ${libro.tienda_nombre} ${mesEnPalabras(libro.mes)}`
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
  const tienda = String(datos.libro.tienda_nombre ?? 'tienda').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
  a.href = url
  a.download = `comisiones_${tienda}_${datos.libro.mes}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
