/**
 * Las primeras hojas de TODOS los Excel de Bonificaciones, con el formato de
 * la plantilla que usan los jefes ("06 DE JUNIO 2026.xlsx").
 *
 * Pedido del usuario (2026-11-25): "los jefes y la gente que ve esto es lo
 * que ellos entienden, este formato, esta plantilla… y nuestra explicación
 * más detallada dejarla en otras pestañas después". Así que cada libro abre
 * con lo de siempre de ellos:
 *
 *   - una hoja por tienda (EL EDÉN, NORTE…): arriba las órdenes con las
 *     columnas de la plantilla (ALMACÉN, FECHA, NOMBRE, CÉDULA, TOTAL DE LA
 *     VENTA, IVA, ABONO, SALDO, FORMA DE PAGO, ASESOR), separadas en ventas,
 *     restauraciones y "NO TIENE EL 50 %"; abajo la cuenta en escalera
 *     (TOTAL VENTAS, MENOS META, IGUAL, MENOS IVA, POR EL 5 %, REPARTIDO) y
 *     lo de cada persona;
 *   - RESTAURACIONES e INDEPENDIENTES (la hoja FREELANCE de ellos);
 *   - CUADRO: resumen por vendedor, una columna por tienda y el TOTAL.
 *
 * Aquí no se calcula ninguna bonificación: las cifras vienen del servidor (la
 * misma cuenta de la pantalla y de Listas). Las fórmulas de Excel solo
 * repiten la escalera para que quien la mire pueda revisarla como la hacía a
 * mano; su resultado ya va guardado.
 *
 * La palabra "pool" no sale: es "la bonificación de la tienda".
 */

import { reglasPara } from './reglasComision'

// Sin importar de excelComisiones: ese archivo importa este.
const n = v => Number(v) || 0
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
export const mesEnPalabras = mes => { const [a, m] = String(mes).split('-'); return `${MESES[Number(m) - 1]} de ${a}` }
/** "2026-10" → "OCTUBRE 2026" */
export const mesTitulo = mes => mesEnPalabras(mes).replace(' de ', ' ').toUpperCase()

const PESOS = '"$"#,##0;[Red]-"$"#,##0'
const NEGRO = 'FF000000'
const AZUL_CLARO = 'FFD6DCE4'   // el gris azulado de la plantilla
const AMARILLO = 'FFFFFF00'

const FINO = { style: 'thin', color: { argb: 'FFBFBFBF' } }
const BORDES = { top: FINO, bottom: FINO, left: FINO, right: FINO }

// Formas de pago que son de restauración o que no son una venta de la tienda.
const RESTAURACION = ['restauracion_5', 'restauracion_equipo', 'rest_compartida']
const DE_OTROS = ['abono_almacen']

// Los nombres de las hojas nuestras, que van después de estas.
const HOJAS_NUESTRAS = ['resumen', 'por persona', 'por tienda', 'por vendedor', 'detalle', 'independientes',
  'metas y equipos', 'reemplazos', 'cómo se calcula', 'sin bonificación', 'todas las órdenes', 'comprobantes',
  'por cobrar', 'todavía no', 'anticipos y abonos', 'la tienda', 'bolsón', 'tiendas', 'personas',
  'cómo leerlo', 'órdenes de la tienda']

const MEDIO = {
  efectivo: 'EFECTIVO', transferencia: 'TRANSFERENCIA', tarjeta: 'TARJETA', addi: 'ADDI',
  otro: 'OTRO', reembolso: 'DEVOLUCIÓN', sin_metodo: 'SIN MEDIO',
}

/** "Decasa Vía El Edén" → "EL EDÉN": el nombre corto, como las pestañas de ellos. */
export function nombreCorto(tienda) {
  return String(tienda ?? 'TIENDA').replace(/^Decasa\s+/i, '').replace(/^V[ií]a\s+/i, '').trim().toUpperCase() || 'TIENDA'
}

function nombreDeHoja(base, usadas) {
  let h = String(base).replace(/[[\]:*?/\\]/g, '').slice(0, 28) || 'HOJA'
  // La tienda "Independientes" chocaba con la hoja nuestra de ese nombre y
  // salía "INDEPENDIENTES 2": mejor "TIENDA INDEPENDIENTES".
  if (usadas.has(h.toLowerCase()) && !usadas.has(`tienda ${h}`.toLowerCase())) h = `TIENDA ${h}`.slice(0, 31)
  let i = 2
  while (usadas.has(h.toLowerCase())) h = `${String(base).slice(0, 25)} ${i++}`
  usadas.add(h.toLowerCase())
  return h
}

const fechaCorta = f => {
  if (!f) return ''
  const [y, m, d] = String(f).slice(0, 10).split('-')
  return d ? `${d}/${m}/${y}` : String(f)
}

/** Una orden de cualquiera de las respuestas, con los mismos nombres. */
export function normalizarOrden(o) {
  const forma = o.forma_pago ?? null
  const total = n(o.valor_total_orden ?? o.valor_orden_entera ?? o.valor_total ?? o.valor ?? o.valor_orden)
  const pagado = o.pagado ?? o.pagado_orden
  return {
    orden_id: o.orden_id ?? o.id_orden ?? null,
    ref: o.orden_referencia ?? o.referencia ?? (o.orden_numero ? `#${o.orden_numero}` : ''),
    fecha: o.fecha_venta ?? o.fecha ?? null,
    cliente: o.cliente_nombre ?? o.cliente ?? '',
    cedula: o.cliente_cedula ?? '',
    total,
    tarjeta: n(o.pagado_tarjeta),
    datafono: n(o.costo_datafono),
    pagado: pagado == null ? null : n(pagado),
    medios: o.medios_de_pago ?? null,
    forma,
    restauracion: RESTAURACION.includes(forma) || (forma !== 'pool' && (!!o.es_restauracion || o.tipo_orden === 'restauracion')),
    fv2: o.tipo_orden === 'fv2' || String(o.orden_referencia ?? o.referencia ?? '').startsWith('FV2'),
    // La marcada a mano "no se resta el IVA" (o ya entera por la regla, según el servidor).
    enteraMarcada: !!o.sin_descontar_iva,
    // Ya cuenta: el cliente pagó la mitad (o lo que pida la regla).
    cuenta: o.req_50_pct ?? o.cumple_pago ?? o.pago_completo ?? (n(o.pct_pagado) >= 50),
    monto: n(o.monto_comision ?? o.paga),
  }
}

function medioTexto(o) {
  if (!o.medios) return ''
  return Object.entries(o.medios).filter(([, v]) => n(v) !== 0)
    .map(([k, v]) => Object.keys(o.medios).length > 1 ? `${MEDIO[k] ?? k.toUpperCase()} ${n(v).toLocaleString('es-CO')}` : (MEDIO[k] ?? k.toUpperCase()))
    .join(' + ')
}

// ── Piezas ───────────────────────────────────────────────────────────────────

const COLUMNAS = [
  ['ALMACÉN', 16], ['FECHA', 12], ['ORDEN', 11], ['NOMBRE', 32], ['CÉDULA', 14],
  ['VALOR REAL VENTA T. CRÉDITO', 18], ['TOTAL DE LA VENTA', 17], ['IVA', 15], ['ABONO', 15],
  ['SALDO', 14], ['FORMA DE PAGO', 26], ['OBSERVACIONES', 44], ['ASESOR', 22], ['BONIFICACIÓN', 15],
]
const C = { ALMACEN: 1, FECHA: 2, ORDEN: 3, NOMBRE: 4, CEDULA: 5, REAL: 6, TOTAL: 7, IVA: 8, ABONO: 9, SALDO: 10, FORMA: 11, OBS: 12, ASESOR: 13, BONI: 14 }
const L = col => String.fromCharCode(64 + col)

function anchos(ws) {
  COLUMNAS.forEach(([, w], i) => { ws.getColumn(i + 1).width = w })
}

function encabezado(ws) {
  const r = ws.addRow(COLUMNAS.map(([t]) => t))
  r.font = { bold: true }
  r.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true }
  r.height = 30
  r.eachCell(c => { c.border = BORDES; c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: AZUL_CLARO } } })
  return r
}

function rotulo(ws, texto, { color = null } = {}) {
  const r = ws.addRow([texto])
  r.font = { bold: true, size: 11, color: color ? { argb: color } : undefined }
  return r
}

/** Las filas de una lista de órdenes; devuelve [primera, última] o null. */
function filasDeOrdenes(ws, tienda, ordenes, { divisor, fv2Entera }) {
  if (!ordenes.length) return null
  let primera = null
  for (const o of ordenes) {
    const r = ws.addRow([])
    const i = r.number
    primera ??= i
    r.getCell(C.ALMACEN).value = tienda
    r.getCell(C.FECHA).value = fechaCorta(o.fecha)
    r.getCell(C.ORDEN).value = o.ref
    r.getCell(C.NOMBRE).value = String(o.cliente ?? '').toUpperCase()
    r.getCell(C.CEDULA).value = o.cedula || ''
    if (o.tarjeta > 0) r.getCell(C.REAL).value = o.total - o.datafono
    r.getCell(C.TOTAL).value = o.total
    const sinIva = o.restauracion || o.enteraMarcada || (o.fv2 && fv2Entera)
    r.getCell(C.IVA).value = sinIva
      ? { formula: `${L(C.TOTAL)}${i}`, result: o.total }
      : { formula: `${L(C.TOTAL)}${i}/${divisor}`, result: Math.round(o.total / divisor) }
    if (o.pagado != null) {
      r.getCell(C.ABONO).value = o.pagado
      r.getCell(C.SALDO).value = { formula: `${L(C.TOTAL)}${i}-${L(C.ABONO)}${i}`, result: o.total - o.pagado }
    }
    r.getCell(C.FORMA).value = medioTexto(o)
    r.getCell(C.OBS).value = o.obs ?? ''
    r.getCell(C.ASESOR).value = (o.asesores ?? []).join(' / ').toUpperCase()
    if (o.monto) r.getCell(C.BONI).value = o.monto
    for (const col of [C.REAL, C.TOTAL, C.IVA, C.ABONO, C.SALDO, C.BONI]) r.getCell(col).numFmt = PESOS
    r.alignment = { vertical: 'top', wrapText: true }
    r.eachCell({ includeEmpty: true }, c => { if (c.col <= COLUMNAS.length) c.border = BORDES })
  }
  return [primera, ws.lastRow.number]
}

function filaSuma(ws, rango, ordenes = [], etiqueta = 'TOTAL') {
  const r = ws.addRow([])
  r.getCell(C.NOMBRE).value = etiqueta
  if (rango) {
    const suma = { [C.TOTAL]: o => o.total, [C.ABONO]: o => n(o.pagado), [C.BONI]: o => o.monto }
    for (const col of [C.TOTAL, C.ABONO, C.BONI]) {
      r.getCell(col).value = { formula: `SUM(${L(col)}${rango[0]}:${L(col)}${rango[1]})`, result: ordenes.reduce((s, o) => s + n(suma[col](o)), 0) }
      r.getCell(col).numFmt = PESOS
    }
  }
  r.font = { bold: true }
  return r
}

/**
 * Un renglón de la escalera: "MENOS | META | 40.000.000". Devuelve la
 * dirección de la celda del valor (para las fórmulas siguientes).
 */
function paso(ws, signo, texto, valor, { formula = null, negrita = false, resaltar = false } = {}) {
  const r = ws.addRow([])
  r.getCell(3).value = signo
  r.getCell(4).value = texto
  const c = r.getCell(C.TOTAL)
  c.value = formula ? { formula, result: valor } : valor
  c.numFmt = PESOS
  if (negrita) r.font = { bold: true }
  if (resaltar) c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: AMARILLO } }
  return `${L(C.TOTAL)}${r.number}`
}

// ── Hoja de una tienda ───────────────────────────────────────────────────────

/** Junta las tarjetas de una tienda en un mes: órdenes sin repetir, quién cobra qué. */
function juntarMes(tarjetas) {
  const porOrden = new Map()
  const sinOrden = []
  for (const t of tarjetas) {
    for (const o0 of t.ordenes ?? []) {
      if (o0.forma_pago === 'parte_pool' || (!o0.orden_id && !o0.orden_referencia && !o0.referencia)) continue
      const o = normalizarOrden(o0)
      const clave = o.orden_id ?? o.ref
      const ya = porOrden.get(clave)
      if (ya) {
        if (!ya.asesores.includes(t.vendedor_nombre)) ya.asesores.push(t.vendedor_nombre)
        ya.monto += o.monto
        if (o.forma === 'fuera_del_pool') ya.forma = o.forma
      } else {
        porOrden.set(clave, { ...o, asesores: [t.vendedor_nombre] })
      }
    }
  }
  const todas = [...porOrden.values()].sort((a, b) => String(a.fecha ?? '').localeCompare(String(b.fecha ?? '')))
  return {
    ventas: todas.filter(o => !o.restauracion && !DE_OTROS.includes(o.forma) && o.forma !== 'fuera_del_pool' && o.cuenta),
    sinMitad: todas.filter(o => !o.restauracion && !DE_OTROS.includes(o.forma) && o.forma !== 'fuera_del_pool' && !o.cuenta),
    restauraciones: todas.filter(o => o.restauracion),
    deOtros: todas.filter(o => DE_OTROS.includes(o.forma)),
    fuera: todas.filter(o => o.forma === 'fuera_del_pool'),
    sinOrden,
  }
}

function seccionMes(ws, ctx, tienda, mes, tarjetas) {
  const r = reglasPara(ctx.reglasDe(mes), { tiendaId: tarjetas[0]?.tienda_id ?? null })
  const divisor = n(r.iva_divisor) || 1.19
  const fv2Entera = r.iva_en_fv2 === false
  const corto = nombreCorto(tienda)
  const g = juntarMes(tarjetas)

  const t = rotulo(ws, `${ctx.etiqueta(mes)} · ${String(tienda).toUpperCase()}`)
  t.font = { bold: true, size: 13 }
  encabezado(ws)

  const ventas = filasDeOrdenes(ws, corto, g.ventas, { divisor, fv2Entera })
  if (ventas) filaSuma(ws, ventas, g.ventas)
  else ws.addRow(['', '', '', '(sin órdenes en esta lista)']).font = { italic: true, size: 9 }

  const lista = (titulo, ordenes, nota) => {
    if (!ordenes.length) return
    ws.addRow([])
    rotulo(ws, titulo)
    if (nota) ws.addRow([nota]).font = { italic: true, size: 9 }
    const rg = filasDeOrdenes(ws, corto, ordenes, { divisor, fv2Entera })
    filaSuma(ws, rg, ordenes)
  }
  lista('RESTAURACIÓN', g.restauraciones, 'A la restauración no se le quita el IVA: es mano de obra.')
  lista('COMPARTIDAS CON UN INDEPENDIENTE', g.deOtros, 'La vendió un independiente con la tienda: a la tienda le toca su parte.')
  lista(`NO TIENE EL ${Math.round(n(r.meta_req_pagado_pct ?? 0.5) * 100)}%`, g.sinMitad,
    'Es venta, pero no suma a la meta hasta que el cliente pague la mitad. Cuando la pague, sale la diferencia en el siguiente pago.')
  lista('NO SUMAN (las sacó un supervisor)', g.fuera, 'No suman a la meta ni pagan bonificación.')

  // ── La cuenta, como la hacían a mano ───────────────────────────────────────
  ws.addRow([])
  const conMeta = tarjetas.find(x => x.cuenta?.por_pool)
  const c = conMeta?.cuenta ?? null
  if (c && (c.trimestre || c.meta_tienda != null)) {
    if (c.trimestre) {
      rotulo(ws, `TRIMESTRAL (${c.trimestre}): la meta se mide con los tres meses juntos.`)
      paso(ws, '', 'BONIFICACIÓN DE LA TIENDA DEL TRIMESTRE', n(c.comision_pool), { negrita: true, resaltar: true })
    } else {
      const meta = n(c.meta_tienda)
      const vendido = n(c.total_tienda_mes)
      const fv2 = fv2Entera ? n(c.ventas_fv2) : 0
      const pct = n(r.pct_pool) || 0.05
      const vTotal = paso(ws, 'VENTAS', 'TOTAL VENTAS QUE CUENTAN', vendido, { negrita: true })
      ws.lastRow.getCell(C.OBS).value = `Con la mitad pagada y sin lo que se lleva el datáfono (${String(Math.round(n(r.franquicia_pct) * 1000) / 10).replace(".", ",")} % de lo pagado con tarjeta). Las compartidas, la mitad.`
      const vMeta = paso(ws, 'MENOS', 'META', meta)
      const igual = vendido - meta
      const vIgual = paso(ws, '', 'IGUAL:', igual, { formula: `${vTotal}-${vMeta}`, negrita: true })
      if (igual <= 0) {
        paso(ws, '', 'NO LLEGÓ A LA META: NO HAY BONIFICACIÓN DE LA TIENDA', 0, { negrita: true })
      } else {
        const sinIva = igual / divisor
        const vSinIva = paso(ws, 'MENOS', `IVA (÷ ${String(divisor).replace('.', ',')})`, sinIva, { formula: `${vIgual}/${divisor}` })
        let base = vSinIva
        if (fv2 > 0) {
          const devuelto = fv2 - fv2 / divisor
          const vFv2 = paso(ws, 'MÁS', 'IVA DE LAS FV2 (a las FV2 no se les quita)', devuelto, { formula: `${fv2}-${fv2}/${divisor}` })
          base = `(${vSinIva}+${vFv2})`
        }
        // La fórmula solo si da lo mismo que el servidor: con tramos, bono o
        // restauraciones que ayudan a la meta la cuenta es otra, y entonces
        // va la cifra que se paga (el detalle, en las hojas de después).
        const calculado = (sinIva + (fv2 > 0 ? fv2 - fv2 / divisor : 0)) * pct
        const cuadra = Math.abs(calculado - n(c.comision_pool)) <= 2
        const vPool = paso(ws, 'POR EL', `${Math.round(pct * 1000) / 10} %`, n(c.comision_pool),
          { formula: cuadra ? `${base}*${pct}` : null, negrita: true, resaltar: true })
        ws.lastRow.getCell(C.OBS).value = cuadra
          ? 'BONIFICACIÓN DE LA TIENDA: se reparte entre el equipo.'
          : 'BONIFICACIÓN DE LA TIENDA (con tramos, bono o restauraciones que ayudaron a la meta: la cuenta completa está en las hojas de detalle).'
        reparto(ws, ctx, tarjetas, c, cuadra ? vPool : null, r)
      }
    }
  } else if (c) {
    paso(ws, '', 'BONIFICACIÓN DE LA TIENDA', n(c.comision_pool), { negrita: true, resaltar: true })
  } else {
    // Sin meta: cada uno cobra su porcentaje de lo suyo.
    const pct = n(r.pct_individual) || 0.05
    const conIva = g.ventas.filter(o => !(o.fv2 && fv2Entera))
    const base = conIva.reduce((s, o) => s + o.total - o.datafono, 0)
    const enteras = g.ventas.filter(o => o.fv2 && fv2Entera).reduce((s, o) => s + o.total - o.datafono, 0)
    const pagado = g.ventas.reduce((s, o) => s + o.monto, 0)
    if (base + enteras > 0) {
      const vT = paso(ws, 'VENTAS', 'TOTAL VENTAS', base, { negrita: true })
      ws.lastRow.getCell(C.OBS).value = `Sin lo que se lleva el datáfono.${enteras ? ' Sin las FV2, que van abajo.' : ''}`
      const vI = paso(ws, 'MENOS', `IVA (÷ ${String(divisor).replace('.', ',')})`, base / divisor, { formula: `${vT}/${divisor}` })
      let suma = vI
      if (enteras) { suma = `(${vI}+${paso(ws, 'MÁS', 'FV2 (sin quitarles el IVA)', enteras)})` }
      const calculado = (base / divisor + enteras) * pct
      const cuadra = Math.abs(calculado - pagado) <= 2 * Math.max(1, g.ventas.length)
      paso(ws, 'POR EL', `${Math.round(pct * 1000) / 10} %  (sin meta: cada uno lo suyo)`, cuadra ? calculado : pagado,
        { formula: cuadra ? `${suma}*${pct}` : null, negrita: true })
    }
  }

  // ── Lo de cada uno ─────────────────────────────────────────────────────────
  ws.addRow([])
  const h = ws.addRow([])
  h.getCell(4).value = 'A CADA UNO'
  h.getCell(C.TOTAL).value = ctx.columnaMonto
  h.font = { bold: true }
  const desde = ws.lastRow.number + 1
  for (const x of tarjetas) {
    const fila = ws.addRow([])
    fila.getCell(4).value = String(x.vendedor_nombre).toUpperCase()
    fila.getCell(C.TOTAL).value = n(x.monto)
    fila.getCell(C.TOTAL).numFmt = PESOS
    if (x.nota) fila.getCell(C.OBS).value = x.nota
  }
  const hastaP = ws.lastRow.number
  const tot = ws.addRow([])
  tot.getCell(4).value = 'TOTAL'
  tot.getCell(C.TOTAL).value = { formula: `SUM(${L(C.TOTAL)}${desde}:${L(C.TOTAL)}${hastaP})`, result: tarjetas.reduce((s, x) => s + n(x.monto), 0) }
  tot.getCell(C.TOTAL).numFmt = PESOS
  tot.font = { bold: true }
  ws.addRow([])
  ws.addRow([])
}

/** "REPARTIDO POR DÍAS": cada persona, sus días y su parte. */
function reparto(ws, ctx, tarjetas, c, celdaPool, r) {
  const conDias = tarjetas.filter(x => x.cuenta?.por_pool)
  if (!conDias.length) return
  const iguales = r.reparto_pool === 'iguales'
  const partes = n(c.partes_dias) || conDias.length
  ws.addRow([])
  const h = ws.addRow([])
  h.getCell(3).value = iguales ? `DIVIDIDO EN ${conDias.length}` : 'REPARTIDO POR DÍAS'
  h.getCell(5).value = iguales ? '' : 'DÍAS'
  h.font = { bold: true }
  for (const x of conDias) {
    const fila = ws.addRow([])
    const dias = n(x.cuenta.parte_dias)
    fila.getCell(4).value = String(x.vendedor_nombre).toUpperCase()
    if (!iguales) fila.getCell(5).value = dias
    const celda = fila.getCell(C.TOTAL)
    const suya = n(x.cuenta.comision_asesor)
    const esperado = iguales ? n(c.comision_pool) / conDias.length : n(c.comision_pool) * dias / partes
    celda.value = !celdaPool || Math.abs(esperado - suya) > 2
      ? suya
      : iguales
        ? { formula: `${celdaPool}/${conDias.length}`, result: suya }
        : { formula: `${celdaPool}*${L(5)}${fila.number}/${partes}`, result: suya }
    celda.numFmt = PESOS
  }
  if (!iguales && n(c.partes_dias) > 0) ws.addRow(['', '', '', `De ${partes} días del equipo en el mes (quien faltó o cubrió, por los días que estuvo).`]).font = { italic: true, size: 9 }
}

function hojaDeTienda(wb, ctx, tienda, tarjetas) {
  const ws = wb.addWorksheet(nombreDeHoja(nombreCorto(tienda), ctx.usadas))
  anchos(ws)
  const meses = [...new Set(tarjetas.map(t => t.mes))].sort()
  for (const mes of meses) seccionMes(ws, ctx, tienda, mes, tarjetas.filter(t => t.mes === mes))
}

// ── Restauraciones e independientes ──────────────────────────────────────────

function hojaRestauraciones(wb, ctx, restauraciones) {
  if (!restauraciones.length) return
  const ws = wb.addWorksheet(nombreDeHoja('RESTAURACIONES', ctx.usadas))
  const cols = [['REFACCIÓN', 11], ['NOMBRE', 30], ['FECHA', 12], ['VALOR REFACCIÓN', 16], ['ABONOS', 15], ['SALDO', 14], ['VENDEDOR', 22], ['ENTRA', 9]]
  cols.forEach(([, w], i) => { ws.getColumn(i + 1).width = w })
  rotulo(ws, `RESTAURACIONES DE LOS INDEPENDIENTES · ${ctx.titulo}`).font = { bold: true, size: 13 }
  const h = ws.addRow(cols.map(([t]) => t))
  h.font = { bold: true }
  h.eachCell(c => { c.border = BORDES; c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFB5C6E8' } } })
  const desde = ws.lastRow.number + 1
  for (const o of restauraciones) {
    const f = ws.addRow([o.ref, String(o.cliente ?? '').toUpperCase(), fechaCorta(o.fecha), o.total, o.pagado ?? 0, null, String(o.vendedor ?? '').toUpperCase(), o.entra ? 'SÍ' : 'NO'])
    f.getCell(6).value = { formula: `D${f.number}-E${f.number}`, result: o.total - n(o.pagado) }
    ;[4, 5, 6].forEach(c => { f.getCell(c).numFmt = PESOS })
    f.eachCell({ includeEmpty: true }, c => { if (c.col <= cols.length) c.border = BORDES })
  }
  const hasta = ws.lastRow.number
  const t = ws.addRow(['', 'TOTAL'])
  t.getCell(4).value = { formula: `SUM(D${desde}:D${hasta})`, result: restauraciones.reduce((s, o) => s + o.total, 0) }
  t.getCell(4).numFmt = PESOS
  t.font = { bold: true }
  ws.addRow([])
  ws.addRow(['', 'Cada independiente cobra el 5 % de lo que entra (el cliente ya pagó la mitad), sin quitarle el IVA. Lo de cada uno, en el CUADRO.']).font = { italic: true, size: 9 }
}

function hojaIndependientes(wb, ctx, tarjetas) {
  if (!tarjetas.length) return
  const ws = wb.addWorksheet(nombreDeHoja('FREELANCE', ctx.usadas))
  anchos(ws)
  for (const t of tarjetas) {
    const r = reglasPara(ctx.reglasDe(t.mes), { usuarioId: t.vendedor_id })
    const divisor = n(r.iva_divisor) || 1.19
    const fv2Entera = r.iva_en_fv2 === false
    rotulo(ws, `${String(t.vendedor_nombre).toUpperCase()} · ${ctx.etiqueta(t.mes)}`).font = { bold: true, size: 13 }
    encabezado(ws)
    const ordenes = (t.ordenes ?? []).map(o => ({ ...normalizarOrden(o), asesores: [t.vendedor_nombre] }))
      .filter(o => !o.restauracion)
      .sort((a, b) => String(a.fecha ?? '').localeCompare(String(b.fecha ?? '')))
    const rg = filasDeOrdenes(ws, 'INDEPENDIENTE', ordenes, { divisor, fv2Entera })
    filaSuma(ws, rg, ordenes)
    ws.addRow([])
    if (n(t.de_sus_ventas)) paso(ws, '', 'DE SUS VENTAS (5 %, ÷ IVA; a las FV2 no)', n(t.de_sus_ventas))
    if (n(t.del_bolson)) paso(ws, 'MÁS', 'BOLSÓN DE RESTAURACIONES (hoja RESTAURACIONES)', n(t.del_bolson))
    paso(ws, '', ctx.columnaMonto, n(t.monto), { negrita: true, resaltar: true })
    ws.addRow([])
    ws.addRow([])
  }
}

// ── CUADRO ───────────────────────────────────────────────────────────────────

function hojaCuadro(wb, ctx, tarjetas, anticipos) {
  const ws = wb.addWorksheet(nombreDeHoja('CUADRO', ctx.usadas))
  const tiendas = [...new Set(tarjetas.filter(t => !t.es_independiente).map(t => nombreCorto(t.tienda_nombre)))].sort()
  const conIndep = tarjetas.some(t => t.es_independiente)
  const conAnticipos = anticipos && [...anticipos.values()].some(v => n(v) !== 0)
  const cols = ['VENDEDOR', ...tiendas, ...(conIndep ? ['INDEPENDIENTES'] : []), ...(conAnticipos ? ['ANTICIPOS Y ABONOS'] : []), ctx.columnaTotal]
  ws.getColumn(1).width = 4
  cols.forEach((_, i) => { ws.getColumn(i + 2).width = i === 0 ? 24 : 16 })
  const ultima = L(cols.length + 1)

  const t1 = ws.addRow(['', 'RESUMEN POR VENDEDOR'])
  ws.mergeCells(`B1:${ultima}1`)
  const t2 = ws.addRow(['', ctx.titulo])
  ws.mergeCells(`B2:${ultima}2`)
  for (const r of [t1, t2]) { r.font = { bold: true, size: 13 }; r.alignment = { horizontal: 'center' } }
  const h = ws.addRow(['', ...cols])
  h.font = { bold: true }
  h.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true }
  h.height = 30
  h.eachCell(c => { if (c.col > 1) { c.border = BORDES; c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: AZUL_CLARO } } } })

  const personas = new Map()
  for (const t of tarjetas) {
    if (!personas.has(t.vendedor_id)) personas.set(t.vendedor_id, { nombre: t.vendedor_nombre, valores: {} })
    const col = t.es_independiente ? 'INDEPENDIENTES' : nombreCorto(t.tienda_nombre)
    const p = personas.get(t.vendedor_id)
    p.valores[col] = n(p.valores[col]) + n(t.monto)
  }
  const desde = ws.lastRow.number + 1
  const lista = [...personas.entries()].sort((a, b) => String(a[1].nombre).localeCompare(String(b[1].nombre), 'es'))
  for (const [vid, p] of lista) {
    const fila = ws.addRow(['', String(p.nombre).toUpperCase()])
    let total = 0
    cols.slice(1, -1).forEach((col, j) => {
      const v = col === 'ANTICIPOS Y ABONOS' ? -n(anticipos?.get(vid)) : p.valores[col]
      if (v) fila.getCell(j + 3).value = v
      total += n(v)
    })
    const ctot = fila.getCell(cols.length + 1)
    ctot.value = { formula: `SUM(C${fila.number}:${L(cols.length)}${fila.number})`, result: total }
    ctot.font = { bold: true }
    fila.eachCell({ includeEmpty: true }, c => { if (c.col > 1 && c.col <= cols.length + 1) { c.border = BORDES; if (c.col > 2) c.numFmt = PESOS } })
  }
  const hasta = ws.lastRow.number
  const tot = ws.addRow(['', `TOTAL BONIFICACIONES`])
  cols.slice(1).forEach((_, j) => {
    const col = L(j + 3)
    let res = 0
    for (let k = desde; k <= hasta; k++) { const v = ws.getRow(k).getCell(j + 3).value; res += n(typeof v === 'object' && v ? v.result : v) }
    tot.getCell(j + 3).value = { formula: `SUM(${col}${desde}:${col}${hasta})`, result: res }
    tot.getCell(j + 3).numFmt = PESOS
  })
  tot.font = { bold: true }
  tot.eachCell(c => { if (c.col > 1) c.border = BORDES })
  if (ctx.notaCuadro) {
    ws.addRow([])
    ws.addRow(['', ctx.notaCuadro]).font = { italic: true, size: 9 }
  }
}

// ── Puerta única ─────────────────────────────────────────────────────────────

/**
 * Pone las hojas del formato de los jefes al principio del libro.
 *
 * @param {Object} wb               el libro de ExcelJS (todavía sin hojas, o las agrega al final y las mueve)
 * @param {Object} o.titulo         "VENTAS OCTUBRE 2026", "PAGOS PENDIENTES AL 25/11/2026"…
 * @param {Array}  o.tarjetas       [{mes, tienda_id, tienda_nombre, es_independiente, vendedor_id,
 *                                    vendedor_nombre, monto, cuenta, ordenes, de_sus_ventas, del_bolson, nota}]
 * @param {Array}  [o.restauraciones] las del bolsón de independientes, normalizadas con vendedor y entra
 * @param {Map}    [o.anticipos]    vendedor_id → lo que se descuenta (anticipos y abonos)
 * @param {string} [o.columnaMonto] cómo se llama lo de cada uno ("BONIFICACIÓN", "LISTO PARA PAGAR")
 * @param {string} [o.columnaTotal] la última columna del CUADRO ("TOTAL", "SE LE ENTREGA")
 * @param {Object} [o.reglas]       las vigentes del mes (cargarReglasVigentes)
 */
export function hojasDeLosJefes(wb, { titulo, tarjetas, restauraciones = [], anticipos = null, columnaMonto = 'BONIFICACIÓN', columnaTotal = 'TOTAL', reglas = null, reglasPorMes = false, notaCuadro = null, reservadas = [] }) {
  const antes = wb.worksheets.length
  wb.calcProperties = { ...(wb.calcProperties ?? {}), fullCalcOnLoad: true }
  const ctx = {
    titulo, columnaMonto, columnaTotal, notaCuadro,
    // Listas y Pagos pendientes guardan las reglas por mes; los demás, las del mes del libro.
    reglasDe: mes => (reglasPorMes ? reglas?.[mes] : reglas) ?? null,
    // Las nuestras, que van después: que ninguna choque con ellas.
    usadas: new Set([...HOJAS_NUESTRAS, ...reservadas.map(h => String(h).toLowerCase()), ...wb.worksheets.map(w => w.name.toLowerCase())]),
    etiqueta: mes => `VENTAS ${mesTitulo(mes)}`,
  }
  const deTienda = tarjetas.filter(t => !t.es_independiente)
  const tiendas = [...new Set(deTienda.map(t => t.tienda_nombre))]
    .sort((a, b) => nombreCorto(a).localeCompare(nombreCorto(b), 'es'))
  for (const t of tiendas) hojaDeTienda(wb, ctx, t, deTienda.filter(x => x.tienda_nombre === t))
  hojaRestauraciones(wb, ctx, restauraciones)
  hojaIndependientes(wb, ctx, tarjetas.filter(t => t.es_independiente))
  if (tarjetas.length) hojaCuadro(wb, ctx, tarjetas, anticipos)

  // Se llama antes de armar las nuestras: así quedan de primeras.
  return wb.worksheets.slice(antes)
}

/** Las restauraciones del bolsón, del formato de /comisiones/independientes. */
export function restauracionesDelBolson(lista = []) {
  return lista.map(o => ({
    ref: o.referencia ?? '', cliente: o.cliente ?? '', fecha: o.fecha ?? null,
    total: n(o.valor), pagado: o.pagado == null ? null : n(o.pagado),
    vendedor: o.de_almacen ? `${o.vendedor} (${o.de_almacen})` : o.vendedor, entra: !!o.lista,
  }))
}

// ── De cada respuesta del servidor a tarjetas (persona + tienda + mes) ───────

/** La cuenta de la bonificación de la tienda, de un renglón enriquecido de /comisiones. */
function cuentaDeRenglon(c) {
  return {
    por_pool: true,
    meta_tienda: n(c.meta_tienda), total_tienda_mes: n(c.total_tienda_mes),
    ventas_fv2: n(c.ventas_fv2_tienda_mes), meta_cumplida: !!c.meta_cumplida,
    comision_pool: n(c.comision_pool), comision_asesor: n(c.comision_asesor),
    parte_dias: n(c.parte_dias), partes_dias: n(c.partes_dias), trimestre: c.trimestre ?? null,
  }
}

/**
 * Los renglones de /comisiones?mes= (Mes completo, Cómo va) y la respuesta de
 * /comisiones/independientes del mes.
 */
export function tarjetasDeRenglones(comisiones = [], indep = null) {
  const indepIds = new Set((indep?.independientes ?? []).map(i => i.vendedor_id))
  const porClave = new Map()
  for (const c of comisiones) {
    if (indepIds.has(c.vendedor_id)) continue
    const k = `${c.vendedor_id}_${c.tienda_id}_${c.mes_venta}`
    if (!porClave.has(k)) {
      porClave.set(k, {
        mes: c.mes_venta, tienda_id: c.tienda_id, tienda_nombre: c.tienda_nombre ?? c.tienda?.nombre ?? '',
        es_independiente: false, vendedor_id: c.vendedor_id, vendedor_nombre: c.vendedor_nombre ?? c.vendedor?.nombre ?? '',
        monto: 0, cuenta: null, ordenes: [],
      })
    }
    const t = porClave.get(k)
    t.monto += n(c.monto_comision)
    t.ordenes.push({ ...c, valor_total_orden: c.valor_orden_entera, pagado: c.pagado_orden })
    if (!t.cuenta && ['pool', 'parte_pool'].includes(c.forma_pago)) t.cuenta = cuentaDeRenglon(c)
  }
  const mes = comisiones[0]?.mes_venta ?? null
  const deIndep = (indep?.independientes ?? []).map(i => ({
    mes: indep.mes ?? mes, tienda_id: null, tienda_nombre: 'Independientes', es_independiente: true,
    vendedor_id: i.vendedor_id, vendedor_nombre: i.nombre,
    monto: n(i.comision), de_sus_ventas: n(i.comision_ventas_propias), del_bolson: n(i.comision_restauraciones),
    cuenta: null,
    ordenes: (indep.ordenes ?? []).filter(o => o.vendedor_id === i.vendedor_id)
      .map(o => ({ ...o, orden_referencia: o.referencia, cliente_nombre: o.cliente, fecha_venta: o.fecha, valor_total_orden: o.valor, monto_comision: o.paga, req_50_pct: o.pago_completo })),
  }))
  return [...porClave.values(), ...deIndep]
}

/** Las restauraciones del bolsón de /comisiones/independientes. */
export function bolsonDe(indep = null) {
  return restauracionesDelBolson([
    ...(indep?.ordenes ?? []).filter(o => o.es_restauracion),
    ...(indep?.restauraciones_almacenes ?? []),
  ])
}

/**
 * Lo de Listas y de Pagos pendientes: cada persona trae un bloque por mes con
 * lo listo y lo que todavía espera la mitad. Lo de cada uno es lo LISTO.
 */
export function tarjetasDeListas(porPagar) {
  const out = []
  const anticipos = new Map()
  for (const p of porPagar?.personas ?? []) {
    anticipos.set(p.vendedor_id, n(anticipos.get(p.vendedor_id)) + n(p.se_descuenta))
    for (const b of p.bloques ?? []) {
      const i = b.independiente ?? {}
      out.push({
        mes: b.mes, tienda_id: b.tienda_id, tienda_nombre: b.tienda_nombre, es_independiente: !!b.es_independiente,
        vendedor_id: p.vendedor_id, vendedor_nombre: p.vendedor_nombre,
        monto: n(b.lista), cuenta: b.cuenta ?? null,
        de_sus_ventas: n(i.por_pagar_ventas), del_bolson: n(i.por_pagar_bolson),
        nota: b.atrasada ? `Atrasado: se debía pagar el ${fechaCorta(b.se_cobra_el)}` : null,
        ordenes: [
          ...(b.ordenes_listas ?? []),
          ...(b.ordenes_pendientes ?? []).map(o => ({ ...o, orden_referencia: o.referencia, cliente_nombre: o.cliente, valor_total_orden: o.valor_total, req_50_pct: !!o.cumple_pago, forma_pago: 'pool', monto_comision: 0 })),
        ],
      })
    }
  }
  return { tarjetas: out, anticipos }
}

/** Lo de /comisiones/libro-tienda (una tienda, un independiente o una persona). */
export function tarjetasDelLibro(libro) {
  const deTienda = (d) => (d.personas ?? []).map(p => ({
    mes: d.mes, tienda_id: d.tienda_id, tienda_nombre: d.tienda_nombre, es_independiente: false,
    vendedor_id: p.vendedor_id, vendedor_nombre: p.vendedor_nombre,
    monto: n(p.comision_mes), cuenta: p.cuenta ?? null, ordenes: p.ordenes ?? [],
    anticipo: n(p.pago?.se_descuenta),
  }))
  let tarjetas
  if (libro.es_persona) tarjetas = (libro.tiendas ?? []).flatMap(deTienda)
  else if (libro.es_independiente) {
    tarjetas = [{
      mes: libro.mes, tienda_id: null, tienda_nombre: 'Independientes', es_independiente: true,
      vendedor_id: libro.vendedor_id, vendedor_nombre: libro.vendedor_nombre,
      monto: n(libro.comision_mes), de_sus_ventas: n(libro.de_sus_ventas), del_bolson: n(libro.del_bolson),
      cuenta: null, ordenes: libro.ordenes ?? [], anticipo: n(libro.pago?.se_descuenta),
    }]
  } else tarjetas = deTienda(libro)
  const anticipos = new Map()
  for (const t of tarjetas) if (t.anticipo) anticipos.set(t.vendedor_id, n(anticipos.get(t.vendedor_id)) + t.anticipo)
  const restauraciones = libro.es_independiente ? restauracionesDelBolson(libro.bolson?.restauraciones ?? []) : []
  return { tarjetas, anticipos, restauraciones }
}
