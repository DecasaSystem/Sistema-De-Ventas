/**
 * El Excel de Comisiones: un libro con todo lo del mes, explicado.
 *
 * No es un volcado de la tabla. Quien lo abre —la jefa, contabilidad, el
 * propio vendedor— tiene que poder ver cuánto cobra cada uno, de dónde sale
 * cada peso y por qué una orden está pendiente, sin abrir la app. Por eso
 * cada hoja lleva su encabezado, sus totales y, en el detalle, la cuenta
 * escrita en palabras.
 *
 * Los filtros de la pantalla mandan: el mes, la vista (por estado, por
 * vendedor o resumen), la pestaña de estado y el vendedor elegido recortan
 * lo que sale. La hoja "Resumen" dice con qué filtros se generó, para que
 * nadie compare dos archivos que no son comparables.
 *
 * Se usa exceljs y no xlsx porque el libro lleva formato (encabezados,
 * moneda, paneles fijos), y la versión gratis de xlsx no lo escribe. Se
 * carga al pulsar el botón, no con la app: pesa y solo lo usa esta pantalla.
 */

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']

const ESTADO = { pendiente: 'Pendiente', lista: 'Lista para pagar', pagada: 'Pagada' }

const FORMA = {
  pool:                'Pool del equipo',
  parte_pool:          'Parte del equipo (no vendió)',
  sin_meta_5:          'Individual 5%',
  restauracion_5:      'Restauración 5%',
  restauracion_equipo: 'Restauración de la tienda (repartida)',
  abono_almacen:       'Lo que dejó un independiente',
}

const CANAL = {
  fisica: 'Física', whatsapp: 'WhatsApp', instagram: 'Instagram', facebook: 'Facebook',
  pagina: 'Página web', red_social: 'Red social', otro: 'Otro',
}

// ── Formato ───────────────────────────────────────────────────────────────────
const FMT_PESOS = '"$"#,##0;[Red]-"$"#,##0'
const FMT_ENTERO = '#,##0'
const FMT_PCT = '0.0"%"'

const VERDE = 'FF166534', VERDE_CLARO = 'FFDCFCE7', GRIS = 'FFF3F4F6', GRIS_TEXTO = 'FF6B7280'
const AMBAR = 'FFFEF3C7', ROJO_CLARO = 'FFFEE2E2'

function mesEnPalabras(mes) {
  const [a, m] = String(mes).split('-')
  return `${MESES[Number(m) - 1]} de ${a}`
}

function fecha(f) {
  if (!f) return ''
  const [y, m, d] = String(f).slice(0, 10).split('-')
  return (d && m && y) ? `${d}/${m}/${y}` : String(f)
}

function pesos(v) {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(v) || 0)
}

function n(v) { return Number(v) || 0 }

function siNo(b) { return b ? 'Sí' : 'No' }

// ── Piezas de hoja ────────────────────────────────────────────────────────────

/** Título grande arriba de la hoja, con una línea gris debajo. */
function titulo(ws, texto, subtitulo) {
  const r1 = ws.addRow([texto])
  r1.font = { size: 15, bold: true, color: { argb: VERDE } }
  r1.height = 24
  if (subtitulo) {
    const r2 = ws.addRow([subtitulo])
    r2.font = { size: 10, color: { argb: GRIS_TEXTO } }
  }
  ws.addRow([])
}

/** Un encabezado de sección dentro de la hoja. */
function seccion(ws, texto, nota) {
  ws.addRow([])
  const r = ws.addRow([texto])
  r.font = { size: 12, bold: true }
  if (nota) {
    const rn = ws.addRow([nota])
    rn.font = { size: 9, italic: true, color: { argb: GRIS_TEXTO } }
  }
}

/**
 * Una tabla: encabezado verde, filas con formato por columna, filtro y
 * fila de totales si se pide. Devuelve la fila de encabezado (para congelar).
 *
 * columnas: [{ titulo, campo, ancho, fmt: 'pesos'|'entero'|'pct'|null, total: 'suma'|null }]
 */
function tabla(ws, columnas, filas, { totales = false, etiquetaTotal = 'TOTAL', zebra = true } = {}) {
  const header = ws.addRow(columnas.map(c => c.titulo))
  header.font = { bold: true, color: { argb: 'FFFFFFFF' } }
  header.alignment = { vertical: 'middle', wrapText: true }
  header.height = 30
  header.eachCell(cell => {
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: VERDE } }
    cell.border = { bottom: { style: 'thin' } }
  })

  const numFmt = { pesos: FMT_PESOS, entero: FMT_ENTERO, pct: FMT_PCT }

  filas.forEach((f, i) => {
    const row = ws.addRow(columnas.map(c => {
      const v = typeof c.campo === 'function' ? c.campo(f) : f[c.campo]
      return v === undefined ? '' : v
    }))
    row.alignment = { vertical: 'top', wrapText: true }
    row.height = alturaPara(row, columnas)
    columnas.forEach((c, j) => {
      const cell = row.getCell(j + 1)
      if (c.fmt && numFmt[c.fmt]) cell.numFmt = numFmt[c.fmt]
      if (zebra && i % 2 === 1) cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: GRIS } }
      if (c.color) {
        const argb = c.color(f)
        if (argb) cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb } }
      }
    })
  })

  if (totales && filas.length) {
    const row = ws.addRow(columnas.map((c, j) => {
      if (j === 0) return etiquetaTotal
      if (c.total === 'suma') return filas.reduce((s, f) => s + n(typeof c.campo === 'function' ? c.campo(f) : f[c.campo]), 0)
      if (c.total === 'cuenta') return filas.length
      return ''
    }))
    row.font = { bold: true }
    columnas.forEach((c, j) => {
      const cell = row.getCell(j + 1)
      cell.border = { top: { style: 'medium' } }
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: VERDE_CLARO } }
      if (c.fmt && numFmt[c.fmt]) cell.numFmt = numFmt[c.fmt]
    })
  }

  columnas.forEach((c, j) => {
    const col = ws.getColumn(j + 1)
    col.width = Math.max(col.width || 0, c.ancho || 14)
  })

  if (filas.length) {
    ws.autoFilter = {
      from: { row: header.number, column: 1 },
      to:   { row: header.number + filas.length, column: columnas.length },
    }
  }

  return header
}

/**
 * Cuántas líneas ocupa la celda más alta de la fila, en puntos. Excel no
 * recalcula la altura al abrir un archivo generado, y el texto envuelto
 * quedaba cortado: el equipo con sus días, la cuenta escrita.
 */
function alturaPara(row, columnas) {
  let lineas = 1
  columnas.forEach((c, j) => {
    const v = row.getCell(j + 1).value
    if (typeof v !== 'string' || !v) return
    const ancho = c.ancho || 14
    const porSaltos = v.split('\n').reduce((s, l) => s + Math.max(1, Math.ceil(l.length / ancho)), 0)
    lineas = Math.max(lineas, porSaltos)
  })
  return Math.min(15 * lineas, 200)
}

/** Pares "Etiqueta: valor" en dos columnas, para las cabeceras. */
function fichas(ws, pares) {
  for (const [k, v, fmt] of pares) {
    const r = ws.addRow([k, v])
    r.getCell(1).font = { bold: true, color: { argb: GRIS_TEXTO } }
    if (fmt === 'pesos') r.getCell(2).numFmt = FMT_PESOS
    if (fmt === 'entero') r.getCell(2).numFmt = FMT_ENTERO
    if (fmt === 'pct') r.getCell(2).numFmt = FMT_PCT
  }
}

/** Párrafos sueltos, una línea por fila, en gris. */
function parrafos(ws, lineas) {
  for (const l of lineas) {
    const r = ws.addRow([l])
    r.font = l.startsWith('•') || l === '' ? { size: 10 } : { size: 10, bold: true }
    r.alignment = { wrapText: true, vertical: 'top' }
  }
}

// ── Textos que explican la cuenta ─────────────────────────────────────────────

/** La cuenta de una comisión, escrita para que se pueda rehacer a mano. */
function comoSeCalculo(c) {
  const valor = n(c.valor_orden)
  const monto = n(c.monto_comision)
  const dias  = n(c.parte_dias), todos = n(c.partes_dias)
  const parte = todos > 0 ? `${dias} de ${todos} días` : (c.divisor_asesores > 1 ? `÷ ${c.divisor_asesores}` : 'todo')

  switch (c.forma_pago) {
    case 'pool': {
      if (!c.meta_cumplida) {
        return `La tienda no llegó a la meta (${pesos(c.total_tienda_mes)} de ${pesos(c.meta_tienda)}): sin excedente no hay pool que repartir.`
      }
      const propor = n(c.total_vendedor_mes) > 0 ? valor / n(c.total_vendedor_mes) : 1
      return `Pool de la tienda ${pesos(c.comision_pool)} = (${pesos(c.total_tienda_mes)} vendido − ${pesos(c.meta_tienda)} meta) ÷ 1,19 × 5%. `
           + `Su parte (${parte}) = ${pesos(c.comision_asesor)}. `
           + `Esta orden pesa ${(propor * 100).toFixed(1)}% de lo que vendió en la tienda → ${pesos(monto)}.`
    }
    case 'parte_pool':
      return c.meta_cumplida
        ? `No vendió este mes, pero es del equipo: pool ${pesos(c.comision_pool)} × su parte (${parte}) = ${pesos(monto)}.`
        : `No vendió y la tienda no llegó a la meta: no hay pool. ${pesos(0)}.`
    case 'sin_meta_5':
      if (c.sin_descontar_iva) {
        return `FV2 especial, sin restar el IVA: ${pesos(valor)} × 5% = ${pesos(monto)}.`
      }
      return `${pesos(valor)} ÷ 1,19 (sin IVA) × 5% = ${pesos(monto)}. Individual: en esta tienda no se reparte.`
    case 'restauracion_5':
      return `Restauración: ${pesos(valor)} × 5% = ${pesos(monto)}. Sin IVA de por medio, sin pool, sin meta.`
    case 'restauracion_equipo':
      return `Su pedazo de una restauración de la tienda: ${pesos(valor)} × 5% = ${pesos(monto)}. El 5% se partió entre los que estaban ese día.`
    case 'abono_almacen':
      return c.es_restauracion
        ? `Su parte de lo que dejó un independiente (restauración): ${pesos(valor)} × 5% = ${pesos(monto)}.`
        : `Su parte de lo que dejó un independiente: ${pesos(valor)} ÷ 1,19 × 5% = ${pesos(monto)}. No le suma a la meta.`
    default:
      return ''
  }
}

/** Por qué está como está: qué requisito falta, o desde cuándo está lista. */
function porQueEsteEstado(c) {
  const e = c.estado_calculado ?? c.estado
  if (e === 'pagada') return `Pagada el ${fecha(c.fecha_pago)}${c.pagada_por?.nombre ? ' por ' + c.pagada_por.nombre : ''}.`
  const faltan = []
  if (c.req_50_aplica !== false && !c.req_50_pct) faltan.push(`el cliente lleva pagado ${n(c.pct_pagado)}% (se necesita 50%)`)
  if (!c.req_mes_vencido) faltan.push(`se paga desde el ${fecha(c.fecha_disponible)}`)
  if (['pool', 'parte_pool'].includes(c.forma_pago) && !c.meta_cumplida && c.periodicidad !== 'trimestral') {
    faltan.push('la tienda no ha llegado a la meta')
  }
  if (e === 'lista') {
    return c.atrasada
      ? `Lista desde el ${fecha(c.fecha_disponible)} — lleva ${Math.abs(n(c.dias_restantes))} días sin pagarse.`
      : 'Cumple todo: ya se puede pagar.'
  }
  return faltan.length ? `Falta: ${faltan.join('; ')}.` : 'Pendiente.'
}

function diasTexto(c) {
  const e = c.estado_calculado ?? c.estado
  if (e === 'pagada') return ''
  const d = n(c.dias_restantes)
  if (d > 0)  return `faltan ${d}`
  if (d === 0) return 'hoy'
  return `${Math.abs(d)} de atraso`
}

// ── Las hojas ─────────────────────────────────────────────────────────────────

function hojaResumen(wb, ctx) {
  const ws = wb.addWorksheet('Resumen')
  ws.getColumn(1).width = 34
  ws.getColumn(2).width = 60

  titulo(ws, `Comisiones — ${mesEnPalabras(ctx.mes)}`,
    `Generado el ${ctx.generadoEl}${ctx.generadoPor ? ' por ' + ctx.generadoPor : ''}`)

  seccion(ws, 'Con qué filtros se generó', 'Lo que hay en las demás hojas es lo que se veía en pantalla con estos filtros.')
  fichas(ws, [
    ['Mes', mesEnPalabras(ctx.mes)],
    ['Vista', ctx.filtros.vista],
    ['Estado', ctx.filtros.estado],
    ['Vendedor', ctx.filtros.vendedor],
    ...(ctx.filtros.agrupacion ? [['Agrupación del resumen', ctx.filtros.agrupacion]] : []),
  ])

  const det = ctx.detalle
  const suma = (arr, campo) => arr.reduce((s, c) => s + n(c[campo]), 0)
  const porEstado = e => det.filter(c => (c.estado_calculado ?? c.estado) === e)

  seccion(ws, 'Totales de lo exportado', 'Suma de las comisiones que salen en la hoja "Detalle" (no incluye a los independientes, que van en su hoja).')
  fichas(ws, [
    ['Comisión total', suma(det, 'monto_comision'), 'pesos'],
    ['   Ya pagada', suma(porEstado('pagada'), 'monto_comision'), 'pesos'],
    ['   Lista para pagar', suma(porEstado('lista'), 'monto_comision'), 'pesos'],
    ['   Todavía pendiente', suma(porEstado('pendiente'), 'monto_comision'), 'pesos'],
    ['Órdenes con comisión', det.filter(c => c.forma_pago !== 'parte_pool').length, 'entero'],
    ['   Pendientes', porEstado('pendiente').length, 'entero'],
    ['   Listas', porEstado('lista').length, 'entero'],
    ['   Pagadas', porEstado('pagada').length, 'entero'],
    ['Valor vendido (base comisionable)', suma(det, 'valor_orden'), 'pesos'],
    ['Personas', new Set(det.map(c => c.vendedor_id)).size, 'entero'],
  ])

  if (ctx.conIndependientes && ctx.indep?.independientes?.length) {
    seccion(ws, 'Independientes', 'Cobran un porcentaje fijo, no por meta. Su detalle está en la hoja "Independientes".')
    fichas(ws, [
      ['Vendieron entre todos', n(ctx.indep.base), 'pesos'],
      ['Comisión total', ctx.indep.independientes.reduce((s, i) => s + n(i.comision), 0), 'pesos'],
      ['Se paga el', fecha(ctx.indep.se_cobra_el)],
    ])
  }

  seccion(ws, 'Qué hay en cada hoja')
  fichas(ws, [
    ['Por vendedor', 'Una fila por persona y tienda: cuánto cobra, de dónde sale y qué le falta.'],
    ['Por tienda', 'Cada tienda contra su meta: vendido, excedente, pool y cómo se reparte.'],
    ['Detalle', 'Cada orden con su comisión, su estado y la cuenta escrita.'],
    ['Independientes', 'Los que cobran porcentaje fijo y los almacenes que les ayudaron.'],
    ['Metas y equipos', 'Meta, equipo, reemplazos y reparto por días de cada tienda.'],
    ['Cómo se calcula', 'Las reglas, en palabras, para leer los números.'],
  ])

  seccion(ws, 'Los tres estados')
  fichas(ws, [
    ['Pendiente', 'Todavía no cumple algo: el cliente no ha pagado la mitad, no ha llegado la fecha de pago, o la tienda no ha llegado a la meta.'],
    ['Lista para pagar', 'Cumple todo. El monto se sigue recalculando hasta que se marque pagada.'],
    ['Pagada', 'Ya se pagó: el monto quedó congelado y no cambia aunque cambie el mes.'],
  ])

  return ws
}

function hojaPorVendedor(wb, ctx) {
  const ws = wb.addWorksheet('Por vendedor')
  titulo(ws, `Por vendedor — ${mesEnPalabras(ctx.mes)}`,
    'Una fila por persona y tienda. Quien cubrió en otra tienda aparece dos veces: cada tienda es un reparto distinto.')

  const indepIds = new Set((ctx.indep?.independientes ?? []).map(i => i.vendedor_id))

  // Agrupar el detalle por persona y tienda: todo lo del pool (meta, total
  // de la tienda, parte en días) es igual en todas las filas del grupo.
  const grupos = new Map()
  for (const c of ctx.detalle) {
    if (indepIds.has(c.vendedor_id)) continue
    const k = `${c.vendedor_id}_${c.tienda_id}`
    if (!grupos.has(k)) grupos.set(k, { primera: c, items: [] })
    grupos.get(k).items.push(c)
  }

  const filas = [...grupos.values()].map(({ primera: p, items }) => {
    const de = forma => items.filter(c => c.forma_pago === forma)
    const suma = (arr, campo) => arr.reduce((s, c) => s + n(c[campo]), 0)
    const estado = e => items.filter(c => (c.estado_calculado ?? c.estado) === e)
    const conOrden = items.filter(c => c.forma_pago !== 'parte_pool')
    const restau = items.filter(c => c.es_restauracion && c.forma_pago !== 'abono_almacen')
    const porPool = de('pool').length + de('parte_pool').length > 0
    const comoCobra = porPool
      ? 'Pool del equipo'
      : (de('sin_meta_5').length ? 'Individual 5%' : (restau.length ? 'Restauraciones' : 'Otros'))

    return {
      vendedor: p.vendedor_nombre, tienda: p.tienda_nombre, como: comoCobra,
      periodicidad: p.periodicidad === 'trimestral' ? `Trimestral (${p.trimestre ?? ''})` : 'Mensual',
      ordenes: conOrden.length,
      vendio: suma(conOrden, 'valor_orden'),
      tarjeta: suma(items, 'pagado_tarjeta'),
      datafono: suma(items, 'costo_datafono'),
      meta: porPool ? n(p.meta_tienda) : null,
      ventas_tienda: porPool ? n(p.total_tienda_mes) : null,
      meta_cumplida: porPool ? siNo(p.meta_cumplida) : '',
      pool: porPool ? n(p.comision_pool) : null,
      parte: porPool && n(p.partes_dias) > 0 ? `${p.parte_dias} de ${p.partes_dias} días` : (porPool ? `÷ ${p.divisor_asesores}` : ''),
      pct_pool: porPool && n(p.partes_dias) > 0 ? n(p.parte_dias) / n(p.partes_dias) * 100 : null,
      del_pool: suma(de('pool'), 'monto_comision'),
      parte_equipo: suma(de('parte_pool'), 'monto_comision'),
      restauraciones: suma(restau, 'monto_comision'),
      de_indep: suma(de('abono_almacen'), 'monto_comision'),
      individual: suma(de('sin_meta_5'), 'monto_comision'),
      total: suma(items, 'monto_comision'),
      pendientes: estado('pendiente').length, listas: estado('lista').length, pagadas: estado('pagada').length,
      dinero_pendiente: suma(estado('pendiente'), 'monto_comision'),
      dinero_listo: suma(estado('lista'), 'monto_comision'),
      dinero_pagado: suma(estado('pagada'), 'monto_comision'),
    }
  }).sort((a, b) => b.total - a.total)

  const header = tabla(ws, [
    { titulo: 'Vendedor', campo: 'vendedor', ancho: 22 },
    { titulo: 'Tienda', campo: 'tienda', ancho: 22 },
    { titulo: 'Cómo cobra', campo: 'como', ancho: 16 },
    { titulo: 'Periodicidad', campo: 'periodicidad', ancho: 16 },
    { titulo: 'Órdenes', campo: 'ordenes', fmt: 'entero', ancho: 9, total: 'suma' },
    { titulo: 'Vendió (base comisionable)', campo: 'vendio', fmt: 'pesos', ancho: 18, total: 'suma' },
    { titulo: 'Pagado con tarjeta', campo: 'tarjeta', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Costo datáfono (no comisiona)', campo: 'datafono', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Meta de la tienda', campo: 'meta', fmt: 'pesos', ancho: 16 },
    { titulo: 'Vendido en la tienda (mes)', campo: 'ventas_tienda', fmt: 'pesos', ancho: 17 },
    { titulo: 'Meta cumplida', campo: 'meta_cumplida', ancho: 10, color: f => f.meta_cumplida === 'No' ? ROJO_CLARO : null },
    { titulo: 'Pool de la tienda', campo: 'pool', fmt: 'pesos', ancho: 15 },
    { titulo: 'Su parte del pool', campo: 'parte', ancho: 16 },
    { titulo: '% del pool', campo: 'pct_pool', fmt: 'pct', ancho: 9 },
    { titulo: 'Comisión por pool', campo: 'del_pool', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Parte del equipo (sin vender)', campo: 'parte_equipo', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Por restauraciones', campo: 'restauraciones', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'De independientes', campo: 'de_indep', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Individual 5%', campo: 'individual', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'COMISIÓN TOTAL', campo: 'total', fmt: 'pesos', ancho: 17, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'Pendientes', campo: 'pendientes', fmt: 'entero', ancho: 10, total: 'suma' },
    { titulo: 'Listas', campo: 'listas', fmt: 'entero', ancho: 8, total: 'suma' },
    { titulo: 'Pagadas', campo: 'pagadas', fmt: 'entero', ancho: 8, total: 'suma' },
    { titulo: '$ Pendiente', campo: 'dinero_pendiente', fmt: 'pesos', ancho: 14, total: 'suma' },
    { titulo: '$ Listo para pagar', campo: 'dinero_listo', fmt: 'pesos', ancho: 14, total: 'suma', color: f => f.dinero_listo > 0 ? VERDE_CLARO : null },
    { titulo: '$ Ya pagado', campo: 'dinero_pagado', fmt: 'pesos', ancho: 14, total: 'suma' },
  ], filas, { totales: true })

  ws.views = [{ state: 'frozen', xSplit: 2, ySplit: header.number }]

  if (indepIds.size) {
    ws.addRow([])
    const r = ws.addRow(['Los independientes no están en esta hoja: cobran distinto y van en la hoja "Independientes".'])
    r.font = { italic: true, color: { argb: GRIS_TEXTO } }
  }
  return ws
}

function hojaPorTienda(wb, ctx) {
  const ws = wb.addWorksheet('Por tienda')
  titulo(ws, `Por tienda — ${mesEnPalabras(ctx.mes)}`,
    'Cada tienda contra su meta. El pool es lo que pasa de la meta, sin IVA, al 5%; se parte entre el equipo por días.')

  const indepIds = new Set((ctx.indep?.independientes ?? []).map(i => i.vendedor_id))
  const porTienda = new Map()
  for (const c of ctx.detalle) {
    if (indepIds.has(c.vendedor_id)) continue
    if (!porTienda.has(c.tienda_id)) porTienda.set(c.tienda_id, [])
    porTienda.get(c.tienda_id).push(c)
  }

  const filas = ctx.metas
    .filter(m => porTienda.has(m.tienda_id) || n(m.meta) > 0)
    .map(m => {
      const items = porTienda.get(m.tienda_id) ?? []
      const p = items.find(c => ['pool', 'parte_pool'].includes(c.forma_pago)) ?? items[0]
      const suma = (arr, campo) => arr.reduce((s, c) => s + n(c[campo]), 0)
      const estado = e => items.filter(c => (c.estado_calculado ?? c.estado) === e)
      const reparto = ctx.reparto[m.tienda_id]
      const equipo = reparto?.partes?.length
        ? reparto.partes.map(x => `${x.nombre}: ${x.dias} días (${x.porcentaje}%)`).join('\n')
        : (m.asesores ?? []).map(a => a.nombre).join('\n')
      const meta = n(m.meta)
      const ventas = p ? n(p.total_tienda_mes) : null
      const trimestral = p?.periodicidad === 'trimestral'
      const av = p?.avance_trimestre

      return {
        tienda: m.nombre,
        comparte: m.comisiones_compartidas ? 'Sí — pool del equipo' : 'No — cada uno el 5% de lo suyo',
        periodicidad: trimestral ? `Trimestral (${p.trimestre})` : 'Mensual',
        meta: meta || null,
        ventas,
        avance: meta > 0 && ventas != null ? ventas / meta * 100 : null,
        cumplida: meta > 0 && p ? siNo(p.meta_cumplida) : (meta > 0 ? '' : 'Sin meta'),
        excedente: meta > 0 && ventas != null ? ventas - meta : null,
        pool: p && m.comisiones_compartidas ? n(p.comision_pool) : null,
        equipo,
        ordenes: items.filter(c => c.forma_pago !== 'parte_pool').length,
        restauraciones: suma(items.filter(c => c.es_restauracion), 'valor_orden'),
        total: suma(items, 'monto_comision'),
        pendientes: estado('pendiente').length, listas: estado('lista').length, pagadas: estado('pagada').length,
        trimestre_acumulado: trimestral && av ? n(av.acumulado) : null,
        trimestre_falta: trimestral && av ? n(av.falta_vender) : null,
        deficit_inicial: trimestral && p ? n(p.deficit_inicial) : null,
        deficit_final: trimestral && p ? n(p.deficit_final) : null,
      }
    })
    .sort((a, b) => b.total - a.total)

  const header = tabla(ws, [
    { titulo: 'Tienda', campo: 'tienda', ancho: 24 },
    { titulo: 'Reparte comisión', campo: 'comparte', ancho: 26 },
    { titulo: 'Periodicidad', campo: 'periodicidad', ancho: 16 },
    { titulo: 'Meta del mes', campo: 'meta', fmt: 'pesos', ancho: 16 },
    { titulo: 'Vendido para la meta', campo: 'ventas', fmt: 'pesos', ancho: 17 },
    { titulo: '% de la meta', campo: 'avance', fmt: 'pct', ancho: 10 },
    { titulo: 'Meta cumplida', campo: 'cumplida', ancho: 11, color: f => f.cumplida === 'No' ? ROJO_CLARO : (f.cumplida === 'Sí' ? VERDE_CLARO : null) },
    { titulo: 'Excedente sobre la meta', campo: 'excedente', fmt: 'pesos', ancho: 16 },
    { titulo: 'Pool a repartir', campo: 'pool', fmt: 'pesos', ancho: 15 },
    { titulo: 'Equipo y reparto por días', campo: 'equipo', ancho: 34 },
    { titulo: 'Órdenes', campo: 'ordenes', fmt: 'entero', ancho: 9, total: 'suma' },
    { titulo: 'En restauraciones (no suman a la meta)', campo: 'restauraciones', fmt: 'pesos', ancho: 17, total: 'suma' },
    { titulo: 'COMISIÓN TOTAL', campo: 'total', fmt: 'pesos', ancho: 17, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'Pendientes', campo: 'pendientes', fmt: 'entero', ancho: 10, total: 'suma' },
    { titulo: 'Listas', campo: 'listas', fmt: 'entero', ancho: 8, total: 'suma' },
    { titulo: 'Pagadas', campo: 'pagadas', fmt: 'entero', ancho: 8, total: 'suma' },
    { titulo: 'Trimestre: acumulado vs meta', campo: 'trimestre_acumulado', fmt: 'pesos', ancho: 17 },
    { titulo: 'Trimestre: falta vender', campo: 'trimestre_falta', fmt: 'pesos', ancho: 16 },
    { titulo: 'Déficit que traía', campo: 'deficit_inicial', fmt: 'pesos', ancho: 14 },
    { titulo: 'Déficit que deja', campo: 'deficit_final', fmt: 'pesos', ancho: 14 },
  ], filas, { totales: true })

  ws.views = [{ state: 'frozen', xSplit: 1, ySplit: header.number }]
  return ws
}

function hojaDetalle(wb, ctx) {
  const ws = wb.addWorksheet('Detalle')
  titulo(ws, `Detalle — ${mesEnPalabras(ctx.mes)}`,
    `Una fila por comisión (${ctx.detalle.length}). "Cómo se calculó" es la cuenta escrita; "Por qué está así" dice qué le falta para pagarse.`)

  const filas = [...ctx.detalle].sort((a, b) =>
    String(a.vendedor_nombre).localeCompare(String(b.vendedor_nombre)) || String(a.fecha_venta).localeCompare(String(b.fecha_venta)))

  const header = tabla(ws, [
    { titulo: 'Vendedor', campo: 'vendedor_nombre', ancho: 20 },
    { titulo: 'Tienda (a la que cuenta)', campo: 'tienda_nombre', ancho: 20 },
    { titulo: 'Orden', campo: c => c.forma_pago === 'parte_pool' ? '(sin orden)' : (c.orden_referencia ?? c.orden_numero ?? ''), ancho: 11 },
    { titulo: 'Cliente', campo: c => c.cliente_nombre ?? '', ancho: 22 },
    { titulo: 'Canal', campo: c => CANAL[c.canal] ?? (c.canal ?? ''), ancho: 11 },
    { titulo: 'Fecha venta', campo: c => fecha(c.fecha_venta), ancho: 11 },
    { titulo: 'Tipo', campo: c => c.forma_pago === 'parte_pool' ? 'Parte del equipo' : (c.es_restauracion ? 'Restauración' : 'Venta'), ancho: 13 },
    { titulo: 'Cómo se paga', campo: c => FORMA[c.forma_pago] ?? 'Pool del equipo', ancho: 22 },
    { titulo: 'Valor (base comisionable)', campo: c => n(c.valor_orden), fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Pagado por el cliente %', campo: c => c.forma_pago === 'parte_pool' ? null : n(c.pct_pagado), fmt: 'pct', ancho: 11 },
    { titulo: 'Cumple 50% pagado', campo: c => c.req_50_aplica === false ? 'No aplica' : siNo(c.req_50_pct), ancho: 10, color: c => c.req_50_aplica !== false && !c.req_50_pct ? AMBAR : null },
    { titulo: 'Pagado con tarjeta', campo: c => n(c.pagado_tarjeta), fmt: 'pesos', ancho: 14 },
    { titulo: 'Costo datáfono', campo: c => n(c.costo_datafono), fmt: 'pesos', ancho: 13 },
    { titulo: 'COMISIÓN', campo: c => n(c.monto_comision), fmt: 'pesos', ancho: 15, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'Estado', campo: c => ESTADO[c.estado_calculado ?? c.estado] ?? '', ancho: 15,
      color: c => ({ pagada: GRIS, lista: VERDE_CLARO, pendiente: AMBAR })[c.estado_calculado ?? c.estado] },
    { titulo: 'Se paga desde', campo: c => fecha(c.fecha_disponible), ancho: 12 },
    { titulo: 'Días', campo: diasTexto, ancho: 12, color: c => c.atrasada ? ROJO_CLARO : null },
    { titulo: 'Meta cumplida', campo: c => ['pool', 'parte_pool'].includes(c.forma_pago) ? siNo(c.meta_cumplida) : 'No aplica', ancho: 11 },
    { titulo: 'Por qué está así', campo: porQueEsteEstado, ancho: 44 },
    { titulo: 'Cómo se calculó', campo: comoSeCalculo, ancho: 70 },
    { titulo: 'Fecha pago', campo: c => fecha(c.fecha_pago), ancho: 11 },
    { titulo: 'Pagada por', campo: c => c.pagada_por?.nombre ?? '', ancho: 16 },
    { titulo: 'Periodicidad', campo: c => c.periodicidad === 'trimestral' ? `Trimestral (${c.trimestre ?? ''})` : 'Mensual', ancho: 14 },
  ], filas, { totales: true })

  ws.views = [{ state: 'frozen', xSplit: 3, ySplit: header.number }]
  return ws
}

function hojaIndependientes(wb, ctx) {
  const d = ctx.indep
  if (!d?.independientes?.length) return null

  const ws = wb.addWorksheet('Independientes')
  ws.getColumn(1).width = 26
  titulo(ws, `Independientes — ${mesEnPalabras(ctx.mes)}`,
    `Cobran el ${Math.round(n(d.porcentaje) * 100)}% fijo de lo suyo (sin IVA). Las restauraciones se suman todas en un bolsón y cada uno cobra el ${Math.round(n(d.porcentaje) * 100)}% de ese total.`)

  fichas(ws, [
    ['Vendieron entre todos', n(d.base), 'pesos'],
    ['   En ventas', n(d.base_venta), 'pesos'],
    ['   En restauraciones', n(d.base_restauracion), 'pesos'],
    ['Restauraciones que subieron los almacenes', n(d.base_restauracion_almacenes), 'pesos'],
    ['Bolsón de restauraciones', n(d.bolson_restauraciones ?? d.base_restauracion), 'pesos'],
    ['Bolsón × %, para cada uno', n(d.comision_restauraciones), 'pesos'],
    ['Se paga el', fecha(d.se_cobra_el)],
    ['¿Ya llegó la fecha?', siNo(d.llego_la_fecha)],
  ])

  const soloEste = ctx.vendedorId ? d.independientes.filter(i => i.vendedor_id === ctx.vendedorId) : d.independientes

  seccion(ws, 'Lo que cobra cada uno')
  tabla(ws, [
    { titulo: 'Independiente', campo: 'nombre', ancho: 26 },
    { titulo: 'Vendió', campo: 'vendio', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: '   en ventas', campo: 'vendio_venta', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: '   en restauraciones', campo: 'vendio_restauracion', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Comisión de lo suyo', campo: 'comision_ventas_propias', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Del bolsón de restauraciones', campo: 'comision_restauraciones', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'COMISIÓN TOTAL', campo: 'comision', fmt: 'pesos', ancho: 16, total: 'suma', color: () => VERDE_CLARO },
    { titulo: 'Listo para pagar', campo: 'comision_lista', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Todavía pendiente', campo: 'comision_pendiente', fmt: 'pesos', ancho: 15, total: 'suma' },
  ], soloEste, { totales: true })

  const ordenes = (d.ordenes ?? []).filter(o => !ctx.vendedorId || o.vendedor_id === ctx.vendedorId)
  if (ordenes.length) {
    seccion(ws, 'Sus órdenes', 'Una restauración no se paga por esta fila: va al bolsón. "Paga" es lo que vale la orden al %, para referencia.')
    tabla(ws, [
      { titulo: 'Orden', campo: 'referencia', ancho: 10 },
      { titulo: 'Vendedor', campo: 'vendedor', ancho: 22 },
      { titulo: 'Cliente', campo: o => o.cliente ?? '', ancho: 22 },
      { titulo: 'Fecha', campo: o => fecha(o.fecha), ancho: 11 },
      { titulo: 'Tipo', campo: o => o.es_restauracion ? 'Restauración' : 'Venta', ancho: 12 },
      { titulo: 'Valor', campo: o => n(o.valor), fmt: 'pesos', ancho: 15, total: 'suma' },
      { titulo: 'Pagado por el cliente', campo: o => n(o.pagado), fmt: 'pesos', ancho: 15 },
      { titulo: 'Cumple 50%', campo: o => siNo(o.pago_completo), ancho: 9, color: o => o.pago_completo ? null : AMBAR },
      { titulo: 'Compartida con', campo: o => o.almacen ?? '', ancho: 20 },
      { titulo: 'Le suma a la meta del almacén', campo: o => n(o.suma_a_meta), fmt: 'pesos', ancho: 15 },
      { titulo: 'Paga', campo: o => n(o.paga), fmt: 'pesos', ancho: 14, total: 'suma' },
      { titulo: 'Estado', campo: o => o.lista ? 'Lista para pagar' : 'Pendiente', ancho: 14, color: o => o.lista ? VERDE_CLARO : AMBAR },
    ], ordenes, { totales: true })
  }

  if (d.almacenes?.length && !ctx.vendedorId) {
    seccion(ws, 'Almacenes que ayudaron', 'Un independiente cerró la venta con un contacto del almacén: la mitad le cuenta a la meta del almacén y su gente se reparte el 5% de esa mitad.')
    tabla(ws, [
      { titulo: 'Almacén', campo: 'nombre', ancho: 26 },
      { titulo: 'Órdenes compartidas', campo: 'ordenes', fmt: 'entero', ancho: 12, total: 'suma' },
      { titulo: 'Valor compartido', campo: 'compartido', fmt: 'pesos', ancho: 16, total: 'suma' },
      { titulo: 'Le suma a su meta', campo: 'suma_a_meta', fmt: 'pesos', ancho: 16, total: 'suma' },
      { titulo: 'Comisión para su gente', campo: 'comision', fmt: 'pesos', ancho: 16, total: 'suma' },
      { titulo: '   de eso, listo', campo: 'comision_lista', fmt: 'pesos', ancho: 14, total: 'suma' },
    ], d.almacenes, { totales: true })
  }

  return ws
}

function hojaMetasEquipos(wb, ctx) {
  const ws = wb.addWorksheet('Metas y equipos')
  titulo(ws, `Metas y equipos — ${mesEnPalabras(ctx.mes)}`,
    'Lo que rige este mes en cada tienda. La meta y el equipo se arrastran de mes a mes hasta que alguien los cambie.')

  tabla(ws, [
    { titulo: 'Tienda', campo: 'nombre', ancho: 26 },
    { titulo: 'Meta del mes', campo: m => n(m.meta) || null, fmt: 'pesos', ancho: 16 },
    { titulo: 'Reparte comisión', campo: m => m.comisiones_compartidas ? 'Sí — pool del equipo' : 'No — cada uno lo suyo', ancho: 22 },
    { titulo: 'Equipo', campo: m => (m.asesores ?? []).map(a => a.nombre).join('\n') || '—', ancho: 30 },
    { titulo: 'Reparto por días (con reemplazos)', campo: m => {
      const r = ctx.reparto[m.tienda_id]
      return r?.partes?.length ? r.partes.map(x => `${x.nombre}: ${x.dias} de ${r.dias_mes} días (${x.porcentaje}%)`).join('\n') : ''
    }, ancho: 40 },
  ], ctx.metas, { zebra: true })

  const reemplazos = ctx.reemplazos ?? []
  seccion(ws, 'Reemplazos y traslados que tocan este mes',
    reemplazos.length ? 'Quien cubre ocupa el puesto: pesa los días que estuvo y el cubierto los pierde. Un traslado entra como uno más.' : 'Ninguno registrado.')
  if (reemplazos.length) {
    tabla(ws, [
      { titulo: 'Tienda', campo: 'tienda_nombre', ancho: 24 },
      { titulo: 'Tipo', campo: r => r.tipo === 'traslado' ? 'Traslado' : 'Reemplazo', ancho: 11 },
      { titulo: 'Quién', campo: 'usuario_nombre', ancho: 22 },
      { titulo: 'A quién cubre', campo: r => r.reemplaza_a ?? '', ancho: 22 },
      { titulo: 'Desde', campo: r => fecha(r.desde), ancho: 11 },
      { titulo: 'Hasta', campo: r => r.hasta ? fecha(r.hasta) : 'sin fecha de regreso', ancho: 18 },
      { titulo: 'Nota', campo: r => r.nota ?? '', ancho: 36 },
    ], reemplazos)
  }
  return ws
}

function hojaComoSeCalcula(wb) {
  const ws = wb.addWorksheet('Cómo se calcula')
  ws.getColumn(1).width = 120
  titulo(ws, 'Cómo se calcula una comisión', 'Las reglas en palabras, para leer los números de las otras hojas.')

  parrafos(ws, [
    '1. Cuándo nace',
    '• Cada vez que se confirma una orden de venta (no borradores ni cotizaciones) se crea una comisión con el vendedor, la tienda, el mes y el valor.',
    '• Si la venta es compartida (dos vendedores, o un independiente con un almacén) el valor se parte a la mitad para cada uno.',
    '• Lo pagado con datáfono no comisiona completo: la franquicia se queda con el 5,5% de eso, y ese pedazo se descuenta de la base.',
    '',
    '2. A qué tienda le cuenta',
    '• Una venta FÍSICA es de la tienda donde se hizo la orden.',
    '• Una venta DIGITAL (WhatsApp, Instagram, Facebook, página) es de la tienda de la persona que la cerró, aunque estuviera cubriendo en otra.',
    '• Un independiente no tiene tienda: todo lo suyo queda en la tienda de la orden.',
    '',
    '3. Tienda que reparte (pool del equipo)',
    '• Pool = (Vendido en la tienda − Meta) ÷ 1,19 × 5%. Si no llega a la meta, el pool es $0.',
    '• El pool se parte entre el equipo por DÍAS: quien estuvo el mes entero pesa 31; quien vino a cubrir pesa los días que cubrió y el cubierto los pierde.',
    '• Lo de cada persona se reparte entre sus órdenes a prorrata del valor: la orden más grande se lleva más.',
    '• Quien es del equipo y no vendió cobra su parte igual (fila "Parte del equipo").',
    '• Quien vende en la tienda sin ser del equipo ni reemplazo cobra el 5% de lo suyo (÷1,19), por fuera del pool. Sus ventas sí empujan la meta.',
    '',
    '4. Tienda que no reparte, o sin meta (Tienda Virtual, Vía Jardines, independientes)',
    '• Cada uno cobra el 5% de lo suyo: Valor ÷ 1,19 × 5%. Nada se divide.',
    '• Los independientes además juntan todas sus restauraciones en un bolsón y cada uno cobra el 5% de ese total.',
    '',
    '5. Restauraciones',
    '• Valor × 5%, sin quitar IVA, sin pool, sin meta. No le suman a la meta de la tienda.',
    '• En una tienda que reparte, ese 5% se parte entre los que estaban en la tienda ese día.',
    '',
    '6. Cuándo se puede pagar (estado "Lista")',
    '• El cliente ya pagó al menos el 50% de la orden (restauraciones, ventas sin meta y lo que deja un independiente). La parte del pool no lo espera: es del equipo y se paga igual a todos.',
    '• Ya llegó la fecha: el 20 del mes siguiente a la venta. En Unicentro Pereira y Circunvalar, el 20 del mes siguiente al cierre del trimestre.',
    '• Si va por pool, la tienda cumplió la meta (en trimestrales se netean los tres meses y un déficit se arrastra al trimestre siguiente).',
    '• Hasta que se marque pagada el monto se recalcula: si cambian las ventas del mes, cambia. Al pagarla queda congelada.',
    '',
    '7. Columnas que confunden',
    '• "Valor (base comisionable)": el valor de la orden menos el costo del datáfono, y a la mitad si es compartida. No es lo que pagó el cliente.',
    '• "Vendido para la meta": suma de las ventas de la tienda que cuentan contra la meta (sin restauraciones, sin canceladas, sin independientes, más lo que un independiente le abonó).',
    '• "Su parte del pool": días suyos sobre días de todo el equipo. En trimestrales, los días del trimestre entero.',
  ])
  return ws
}

// ── Punto de entrada ──────────────────────────────────────────────────────────

/**
 * Arma y descarga el libro.
 *
 * @param {Object} datos
 * @param {string}   datos.mes            'YYYY-MM'
 * @param {Array}    datos.comisiones     todas las del mes (respuesta de /comisiones)
 * @param {Object}   datos.indep          respuesta de /comisiones/independientes (o null)
 * @param {Array}    datos.metas          respuesta de /comisiones/metas
 * @param {Array}    datos.reemplazos     respuesta de /comisiones/reemplazos .reemplazos
 * @param {Object}   datos.reparto        { tienda_id: reparto } de /comisiones/reemplazos
 * @param {Object}   datos.filtros        { vista, estado, vendedorId, vendedorNombre, agrupacion }
 * @param {string}  [datos.generadoPor]
 */
export async function exportarComisionesExcel(datos) {
  const wb = await armarLibro(datos)
  const f  = datos.filtros

  const buffer = await wb.xlsx.writeBuffer()
  const blob   = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url    = URL.createObjectURL(blob)
  const a      = document.createElement('a')
  const sufijo = [f.vendedorNombre, f.estado ? ESTADO[f.estado] : null].filter(Boolean)
    .map(s => s.toLowerCase().replace(/[^a-z0-9]+/gi, '_')).join('_')
  a.href     = url
  a.download = `comisiones_${datos.mes}${sufijo ? '_' + sufijo : ''}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

/** El libro armado, sin descargarlo: lo usa la exportación y las pruebas. */
export async function armarLibro(datos) {
  const ExcelJS = (await import('exceljs')).default ?? (await import('exceljs'))
  const wb = new ExcelJS.Workbook()
  wb.creator = 'Decasa'
  wb.created = new Date()

  const f = datos.filtros
  // Los independientes no van por las filas de comisiones: cobran un
  // porcentaje fijo y reparten las restauraciones entre ellos, así que sus
  // filas dicen otra cosa. Van en su hoja, con sus números.
  const indepIds = new Set((datos.indep?.independientes ?? []).map(i => i.vendedor_id))
  const esIndep  = !!f.vendedorId && indepIds.has(f.vendedorId)

  // Los filtros recortan el detalle; lo demás se calcula desde ahí.
  let detalle = (datos.comisiones ?? []).filter(c => !indepIds.has(c.vendedor_id))
  if (f.vendedorId) detalle = detalle.filter(c => c.vendedor_id === f.vendedorId)
  if (f.estado)     detalle = detalle.filter(c => (c.estado_calculado ?? c.estado) === f.estado)

  const ahora = new Date()
  const ctx = {
    mes: datos.mes,
    detalle,
    indep: datos.indep,
    metas: datos.metas ?? [],
    reemplazos: datos.reemplazos ?? [],
    reparto: datos.reparto ?? {},
    vendedorId: f.vendedorId ?? null,
    // Con un vendedor de tienda elegido, los independientes no son lo que se mira.
    conIndependientes: !f.vendedorId || esIndep,
    generadoPor: datos.generadoPor,
    generadoEl: `${fecha(ahora.toISOString())} ${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}`,
    filtros: {
      vista: { estado: 'Por estado', vendedor: 'Por vendedor', resumen: 'Resumen' }[f.vista] ?? f.vista,
      estado: f.estado ? ESTADO[f.estado] : 'Todos (pendientes, listas y pagadas)',
      vendedor: f.vendedorNombre ?? 'Todos',
      agrupacion: f.vista === 'resumen'
        ? ({ general: 'General', tienda: 'Por tienda', vendedor: 'Por vendedor' }[f.agrupacion] ?? f.agrupacion)
        : null,
    },
  }

  hojaResumen(wb, ctx)
  // En el resumen, la agrupación que se estaba mirando va de primera.
  if (f.vista === 'resumen' && f.agrupacion === 'tienda') {
    hojaPorTienda(wb, ctx); hojaPorVendedor(wb, ctx)
  } else {
    hojaPorVendedor(wb, ctx); hojaPorTienda(wb, ctx)
  }
  hojaDetalle(wb, ctx)
  if (ctx.conIndependientes) hojaIndependientes(wb, ctx)
  hojaMetasEquipos(wb, ctx)
  hojaComoSeCalcula(wb)

  return wb
}
