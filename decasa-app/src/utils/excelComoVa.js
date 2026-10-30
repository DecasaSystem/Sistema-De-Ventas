/**
 * El Excel "Cómo va el mes": el mes en curso, a la fecha y hora en que se saca.
 *
 * Pedido del dueño (2026-10-30): que un supervisor pueda ver cómo va el mes,
 * entendible para todos. No es lo que se paga (eso es el libro de Listas):
 * es un ESTIMADO de qué pasaría si el mes cerrara hoy, y sobre todo qué falta:
 * cuánto le falta a cada tienda para la meta, cuánto hay que vender por día y
 * qué órdenes no cuentan todavía porque el cliente no ha pagado la mitad (a
 * quién llamar a cobrar).
 *
 * Todo sale de las mismas cuentas de la pantalla: los renglones de
 * /comisiones?mes= (lo estimado de cada persona y el pool "si cerrara hoy") y
 * /comisiones/metas?avance=1 (vendido y lo que ya cuenta, la cuenta de
 * Reportes y Estadísticas). Aquí no se calcula ninguna comisión: solo se suma
 * y se resta lo que ya viene.
 *
 * Lo estimado va en ámbar y con la palabra ESTIMADO, para que nadie lo
 * confunda con lo que se paga.
 */

import { titulo, seccion, tabla, fichas, mesEnPalabras, fecha, pesos, n, FORMA, VERDE_CLARO, AMBAR, ROJO_CLARO, GRIS_TEXTO } from './excelComisiones'
import { reglasPara, laParte, partePagada, cuandoSePaga, cuentaPool, num } from './reglasComision'

let VIGENTES = null
const R = (tiendaId = null, usuarioId = null) => reglasPara(VIGENTES, { tiendaId, usuarioId })

// Renglones que no son una venta de alguien: no se cobran a ningún cliente.
const NO_SON_VENTA = ['parte_pool', 'abono_almacen', 'bolson_restauraciones', 'fuera_del_pool']

/** Días del mes que quedan contando hoy, y los del mes. */
function diasDelMes(mes, hoy) {
  const [a, m] = mes.split('-').map(Number)
  const total = new Date(a, m, 0).getDate()
  const esEste = hoy.getFullYear() === a && hoy.getMonth() + 1 === m
  return { total, quedan: esEste ? total - hoy.getDate() + 1 : 0 }
}

/** Banda ámbar que dice ESTIMADO: va arriba de cada hoja. */
function avisoEstimado(ws, ctx) {
  const r = ws.addRow([`ESTIMADO — cifras al ${ctx.generadoEl}. Si el mes cerrara hoy; todavía puede cambiar. Lo que se paga sale en Comisiones → Listas.`])
  r.font = { bold: true, color: { argb: 'FF92400E' } }
  r.getCell(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: AMBAR } }
  ws.addRow([])
}

// ── Las cuentas ───────────────────────────────────────────────────────────────

function armarTiendas(ctx) {
  const { total, quedan } = ctx.dias
  return ctx.metas
    .map(t => {
      const filas = ctx.comisiones.filter(c => c.tienda_id === t.tienda_id)
      const filaPool = filas.find(c => ['pool', 'parte_pool'].includes(c.forma_pago))
      const meta = n(t.meta)
      const cuenta = n(t.cuenta)
      const vendido = n(t.vendido)
      const falta = meta > 0 ? Math.max(0, meta - cuenta) : 0
      const trimestral = t.periodicidad === 'trimestral'
      const at = filaPool?.avance_trimestre ?? null
      return {
        tienda: t.nombre,
        periodicidad: trimestral ? `Trimestral (${filaPool?.trimestre ?? ''})` : 'Mensual',
        como: !meta ? 'Sin meta' : (t.comisiones_compartidas ? 'Pool del equipo' : 'Cada uno lo suyo'),
        meta: meta || null,
        vendido,
        cuenta,
        no_cuenta: Math.max(0, vendido - cuenta),
        falta: meta ? falta : null,
        avance: meta ? cuenta / meta * 100 : null,
        llego: meta ? (cuenta >= meta ? 'Sí' : 'No') : '',
        por_dia: meta && falta > 0 && quedan > 0 ? Math.ceil(falta / quedan) : null,
        pool: filaPool ? n(filaPool.comision_pool) : (meta ? 0 : null),
        trimestre: trimestral && at
          ? (n(at.falta_vender) > 0 ? `Al trimestre le falta vender ${pesos(at.falta_vender)}` : `El trimestre va arriba ${pesos(at.saldo)}`)
          : '',
        personas: new Set(filas.map(c => c.vendedor_id)).size,
        _hayAlgo: meta > 0 || vendido > 0,
      }
    })
    .filter(t => t._hayAlgo)
    .sort((a, b) => String(a.tienda).localeCompare(String(b.tienda)))
    .map(t => ({ ...t, dias_quedan: t.meta ? quedan : null, dias_mes: total }))
}

/** Las órdenes que todavía no cuentan: una fila por orden (la compartida sale una vez). */
function armarPorCobrar(ctx) {
  const porOrden = new Map()
  for (const c of ctx.comisiones) {
    if (!c.orden_id || NO_SON_VENTA.includes(c.forma_pago)) continue
    if ((c.estado_calculado ?? c.estado) === 'pagada' || c.req_50_pct) continue
    const r = R(c.tienda_id, c.vendedor_id)
    const pct = Math.max(n(r.req_pagado_pct), n(r.meta_req_pagado_pct))
    const valor = n(c.valor_orden_entera) || n(c.valor_orden)
    const pagado = n(c.pagado_orden)
    if (!porOrden.has(c.orden_id)) {
      porOrden.set(c.orden_id, {
        referencia: c.orden_referencia ?? (c.orden_numero ? `#${c.orden_numero}` : `Orden ${c.orden_id}`),
        fecha: fecha(c.fecha_venta ?? c.created_at),
        cliente: c.cliente_nombre ?? '',
        vendedores: new Set(),
        tienda: c.tienda_nombre ?? '',
        tipo: c.es_restauracion ? 'Restauración' : 'Venta',
        valor, pagado,
        pct_pagado: valor > 0 ? pagado / valor * 100 : 0,
        falta: Math.max(0, Math.ceil(valor * pct - pagado)),
        necesita: `${laParte(pct)} (${pesos(Math.ceil(valor * pct))})`,
        suma_meta: c.es_restauracion ? 'No (restauración)' : (n(c.meta_tienda) > 0 ? 'Sí' : 'No (sin meta)'),
      })
    }
    porOrden.get(c.orden_id).vendedores.add(c.vendedor_nombre)
  }
  return [...porOrden.values()]
    .map(o => ({ ...o, vendedores: [...o.vendedores].filter(Boolean).join(', ') }))
    // Por tienda y, adentro, las que con menos plata entran: las primeras llamadas.
    .sort((a, b) => String(a.tienda).localeCompare(String(b.tienda)) || a.falta - b.falta)
}

function armarPersonas(ctx) {
  const grupos = new Map()
  for (const c of ctx.comisiones) {
    if (ctx.indepIds.has(c.vendedor_id)) continue
    const k = `${c.vendedor_id}_${c.tienda_id}`
    if (!grupos.has(k)) grupos.set(k, { p: c, items: [] })
    grupos.get(k).items.push(c)
  }
  return [...grupos.values()].map(({ p, items }) => {
    const ventas = items.filter(c => c.orden_id && !NO_SON_VENTA.includes(c.forma_pago))
    const formas = new Set(items.map(c => c.forma_pago))
    const como = formas.has('pool') || formas.has('parte_pool') ? 'Pool del equipo'
      : formas.has('reemplazo_ventas') ? 'Reemplazo (por lo que vendió)'
      : formas.has('sin_meta_5') ? 'Individual'
      : [...formas].map(f => FORMA[f] ?? f).join(', ')
    const pagada = items.filter(c => (c.estado_calculado ?? c.estado) === 'pagada')
    return {
      vendedor: p.vendedor_nombre,
      tienda: p.tienda_nombre,
      como,
      ordenes: ventas.length,
      vendido: ventas.reduce((s, c) => s + n(c.valor_orden), 0),
      cuenta: ventas.filter(c => c.req_50_pct).reduce((s, c) => s + n(c.valor_orden), 0),
      no_cuenta: ventas.filter(c => !c.req_50_pct).length,
      estimado: items.reduce((s, c) => s + n(c.monto_comision), 0),
      ya_pagado: pagada.reduce((s, c) => s + n(c.monto_comision), 0),
    }
  }).sort((a, b) => String(a.tienda).localeCompare(String(b.tienda)) || b.estimado - a.estimado)
}

// ── Las hojas ─────────────────────────────────────────────────────────────────

function hojaResumen(wb, ctx, tiendas, porCobrar, personas) {
  const ws = wb.addWorksheet('Resumen')
  ws.getColumn(1).width = 40
  ws.getColumn(2).width = 62

  titulo(ws, `Cómo va el mes — ${mesEnPalabras(ctx.mes)}`,
    `Generado el ${ctx.generadoEl}${ctx.generadoPor ? ' por ' + ctx.generadoPor : ''}`)
  avisoEstimado(ws, ctx)

  const r = R()
  const conMeta = tiendas.filter(t => t.meta)
  seccion(ws, 'El mes en una mirada')
  fichas(ws, [
    ['Días que quedan del mes (contando hoy)', `${ctx.dias.quedan} de ${ctx.dias.total}`],
    ['Vendido entre todas las tiendas', tiendas.reduce((s, t) => s + t.vendido, 0), 'pesos'],
    [`Ya cuenta (con ${partePagada(r.meta_req_pagado_pct)})`, tiendas.reduce((s, t) => s + t.cuenta, 0), 'pesos'],
    ['Tiendas que ya llegaron a la meta', `${conMeta.filter(t => t.llego === 'Sí').length} de ${conMeta.length}`],
    ['Órdenes que todavía no cuentan', porCobrar.length, 'entero'],
    ['   Lo que falta cobrar para que cuenten', porCobrar.reduce((s, o) => s + o.falta, 0), 'pesos'],
    ['Comisión estimada si cerrara hoy (tiendas)', personas.reduce((s, p) => s + p.estimado, 0), 'pesos'],
    ...(ctx.indep?.independientes?.length
      ? [['Comisión estimada de los independientes', ctx.indep.independientes.reduce((s, i) => s + n(i.comision), 0), 'pesos']]
      : []),
    ['Se paga', cuandoSePaga(r)],
  ])

  seccion(ws, 'Qué hay en cada hoja')
  fichas(ws, [
    ['Tiendas', 'Cada tienda contra su meta: cuánto lleva, cuánto le falta, cuánto hay que vender por día y el pool si cerrara hoy.'],
    ['Por cobrar', 'Las órdenes que todavía no cuentan porque el cliente no ha pagado lo necesario, con cuánto le falta a cada una.'],
    ['Personas', 'Lo que lleva cada persona y la comisión que le daría si el mes cerrara hoy.'],
    ['Cómo leerlo', 'Qué quiere decir cada columna y las reglas del mes.'],
  ])
  return ws
}

function hojaTiendas(wb, ctx, tiendas) {
  const ws = wb.addWorksheet('Tiendas')
  titulo(ws, `Tiendas — ${mesEnPalabras(ctx.mes)}`,
    `La meta se mide solo con lo que tiene ${partePagada(R().meta_req_pagado_pct)}. Lo demás es venta, pero todavía no suma.`)
  avisoEstimado(ws, ctx)

  const header = tabla(ws, [
    { titulo: 'Tienda', campo: 'tienda', ancho: 24 },
    { titulo: 'Cómo cobra', campo: 'como', ancho: 16 },
    { titulo: 'Periodicidad', campo: 'periodicidad', ancho: 16 },
    { titulo: 'Meta del mes', campo: 'meta', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Vendido (todo)', campo: 'vendido', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Ya cuenta para la meta', campo: 'cuenta', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Vendido que todavía no cuenta', campo: 'no_cuenta', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: '% de la meta', campo: 'avance', fmt: 'pct', ancho: 10,
      color: t => t.avance == null ? null : (t.avance >= 100 ? VERDE_CLARO : AMBAR) },
    { titulo: 'Ya llegó', campo: 'llego', ancho: 9, color: t => t.llego === 'Sí' ? VERDE_CLARO : (t.llego === 'No' ? ROJO_CLARO : null) },
    { titulo: 'Falta para la meta', campo: 'falta', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Días que quedan', campo: 'dias_quedan', fmt: 'entero', ancho: 9 },
    { titulo: 'Hay que sumar por día', campo: 'por_dia', fmt: 'pesos', ancho: 15 },
    { titulo: 'Pool si cerrara hoy (ESTIMADO)', campo: 'pool', fmt: 'pesos', ancho: 17, total: 'suma', color: () => AMBAR },
    { titulo: 'Trimestre', campo: 'trimestre', ancho: 30 },
  ], tiendas, { totales: true })
  ws.views = [{ state: 'frozen', xSplit: 1, ySplit: header.number }]

  ws.addRow([])
  const nota = ws.addRow(['"Hay que sumar por día" es lo que tiene que entrar a la cuenta cada día que queda: ventas nuevas con la mitad pagada o abonos que completen la mitad de las que ya están (hoja "Por cobrar").'])
  nota.font = { italic: true, size: 9, color: { argb: GRIS_TEXTO } }
  return ws
}

function hojaPorCobrar(wb, ctx, porCobrar) {
  const ws = wb.addWorksheet('Por cobrar')
  const r = R()
  titulo(ws, `Por cobrar — ${mesEnPalabras(ctx.mes)}`,
    `Ventas del mes que todavía no cuentan: el cliente no ha pagado ${laParte(r.req_pagado_pct)}. Cuando lo pague, suman a la meta y su comisión se puede pagar. Ordenadas por tienda y, adentro, de la que menos le falta a la que más.`)
  avisoEstimado(ws, ctx)

  if (!porCobrar.length) {
    ws.addRow(['Todas las ventas del mes ya cuentan.'])
    return ws
  }
  const header = tabla(ws, [
    { titulo: 'Orden', campo: 'referencia', ancho: 11 },
    { titulo: 'Fecha', campo: 'fecha', ancho: 11 },
    { titulo: 'Cliente', campo: 'cliente', ancho: 26 },
    { titulo: 'Vendedor(es)', campo: 'vendedores', ancho: 24 },
    { titulo: 'Tienda', campo: 'tienda', ancho: 20 },
    { titulo: 'Tipo', campo: 'tipo', ancho: 12 },
    { titulo: 'Valor de la orden', campo: 'valor', fmt: 'pesos', ancho: 15, total: 'suma' },
    { titulo: 'Ya pagó', campo: 'pagado', fmt: 'pesos', ancho: 14, total: 'suma' },
    { titulo: '% pagado', campo: 'pct_pagado', fmt: 'pct', ancho: 9 },
    { titulo: 'Necesita', campo: 'necesita', ancho: 20 },
    { titulo: 'FALTA COBRAR', campo: 'falta', fmt: 'pesos', ancho: 15, total: 'suma', color: () => AMBAR },
    { titulo: 'Suma a la meta', campo: 'suma_meta', ancho: 16 },
  ], porCobrar, { totales: true, etiquetaTotal: `TOTAL (${porCobrar.length})` })
  ws.views = [{ state: 'frozen', xSplit: 1, ySplit: header.number }]
  return ws
}

function hojaPersonas(wb, ctx, personas) {
  const ws = wb.addWorksheet('Personas')
  titulo(ws, `Personas — ${mesEnPalabras(ctx.mes)}`,
    'Una fila por persona y tienda. En una tienda con pool todos los del equipo cobran su parte igual; lo estimado es lo que le daría si el mes cerrara hoy.')
  avisoEstimado(ws, ctx)

  const header = tabla(ws, [
    { titulo: 'Persona', campo: 'vendedor', ancho: 24 },
    { titulo: 'Tienda', campo: 'tienda', ancho: 22 },
    { titulo: 'Cómo cobra', campo: 'como', ancho: 18 },
    { titulo: 'Órdenes', campo: 'ordenes', fmt: 'entero', ancho: 9, total: 'suma' },
    { titulo: 'Vendió', campo: 'vendido', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Ya cuenta', campo: 'cuenta', fmt: 'pesos', ancho: 16, total: 'suma' },
    { titulo: 'Órdenes que no cuentan', campo: 'no_cuenta', fmt: 'entero', ancho: 11, total: 'suma' },
    { titulo: 'Comisión si cerrara hoy (ESTIMADO)', campo: 'estimado', fmt: 'pesos', ancho: 18, total: 'suma', color: () => AMBAR },
    { titulo: 'De eso, ya pagado', campo: 'ya_pagado', fmt: 'pesos', ancho: 14, total: 'suma' },
  ], personas, { totales: true })
  ws.views = [{ state: 'frozen', xSplit: 1, ySplit: header.number }]

  const indep = ctx.indep?.independientes ?? []
  if (indep.length) {
    seccion(ws, 'Independientes', `Cobran un porcentaje fijo, sin meta. Se pagan el ${fecha(ctx.indep.se_cobra_el)}.`)
    tabla(ws, [
      { titulo: 'Persona', campo: 'nombre', ancho: 24 },
      { titulo: 'Vendió', campo: i => n(i.vendio ?? i.base ?? i.total_ventas), fmt: 'pesos', ancho: 16, total: 'suma' },
      { titulo: 'Comisión si cerrara hoy (ESTIMADO)', campo: i => n(i.comision), fmt: 'pesos', ancho: 18, total: 'suma', color: () => AMBAR },
    ], indep, { totales: true, filtro: false })
  }
  return ws
}

function hojaComoLeerlo(wb, ctx) {
  const ws = wb.addWorksheet('Cómo leerlo')
  ws.getColumn(1).width = 34
  ws.getColumn(2).width = 90
  const r = R()
  titulo(ws, 'Cómo leerlo', `Las reglas que rigen en ${mesEnPalabras(ctx.mes)}.`)
  fichas(ws, [
    ['ESTIMADO', 'Lo que daría si el mes cerrara hoy. Cambia con cada venta y cada abono. Lo que de verdad se paga sale en Comisiones → Listas, el día de pago.'],
    ['Vendido', 'Todo lo que se vendió en el mes, haya pagado o no el cliente. No cuenta lo cancelado ni las restauraciones.'],
    ['Ya cuenta para la meta', `Lo vendido cuyo cliente ya pagó ${laParte(r.meta_req_pagado_pct)}. Es lo único que mide la meta y arma el pool.`],
    ['Falta para la meta', 'Meta menos lo que ya cuenta.'],
    ['Hay que sumar por día', 'Lo que falta para la meta repartido en los días que quedan (contando hoy).'],
    ['Pool', `Lo que pasa de la meta ${cuentaPool(r)}. Se parte entre el equipo ${r.reparto_pool === 'iguales' ? 'en partes iguales' : 'por los días que trabajó cada uno'}.`],
    ['Falta cobrar', `Lo que tiene que abonar el cliente para llegar a ${laParte(r.req_pagado_pct)} de su orden. Con eso la venta cuenta y su comisión se puede pagar.`],
    ['Las 3 condiciones para cobrar', `El cliente pagó ${laParte(r.req_pagado_pct)}, llegó el día de pago (${cuandoSePaga(r)}) y la tienda llegó a la meta.`],
    ['Tiendas trimestrales', 'La meta se suma en los tres meses del trimestre y el pool se paga al cerrarlo; la columna "Trimestre" dice cómo va.'],
    ['Comisión individual', `En una tienda que no reparte, cada uno cobra el ${num(r.pct_individual)}% de lo suyo.`],
  ])
  return ws
}

/**
 * @param {Object} datos
 * @param {string} datos.mes          'YYYY-MM' (el mes en curso)
 * @param {Array}  datos.comisiones   /comisiones?mes=
 * @param {Array}  datos.metas        /comisiones/metas?mes=&avance=1
 * @param {Object} [datos.indep]      /comisiones/independientes?mes=
 * @param {Object} [datos.reglas]     cargarReglasVigentes(mes)
 * @param {string} [datos.generadoPor]
 */
export async function armarLibroComoVa(datos) {
  const ExcelJS = (await import('exceljs')).default ?? (await import('exceljs'))
  const wb = new ExcelJS.Workbook()
  wb.creator = 'Decasa'
  wb.created = new Date()
  VIGENTES = datos.reglas ?? null

  const ahora = datos.ahora ?? new Date()
  const indepIds = new Set((datos.indep?.independientes ?? []).map(i => i.vendedor_id))
  const ctx = {
    mes: datos.mes,
    comisiones: datos.comisiones ?? [],
    metas: datos.metas ?? [],
    indep: datos.indep,
    indepIds,
    dias: diasDelMes(datos.mes, ahora),
    generadoPor: datos.generadoPor,
    generadoEl: `${fecha(`${ahora.getFullYear()}-${String(ahora.getMonth() + 1).padStart(2, '0')}-${String(ahora.getDate()).padStart(2, '0')}`)} ${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}`,
  }

  const tiendas = armarTiendas(ctx)
  const porCobrar = armarPorCobrar(ctx)
  const personas = armarPersonas(ctx)

  hojaResumen(wb, ctx, tiendas, porCobrar, personas)
  hojaTiendas(wb, ctx, tiendas)
  hojaPorCobrar(wb, ctx, porCobrar)
  hojaPersonas(wb, ctx, personas)
  hojaComoLeerlo(wb, ctx)
  return wb
}

/** Arma y descarga el libro "Cómo va el mes". */
export async function exportarComoVaExcel(datos) {
  const wb = await armarLibroComoVa(datos)
  const buffer = await wb.xlsx.writeBuffer()
  const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  const ahora = datos.ahora ?? new Date()
  const hoy = `${ahora.getFullYear()}-${String(ahora.getMonth() + 1).padStart(2, '0')}-${String(ahora.getDate()).padStart(2, '0')}`
  a.href = url
  a.download = `comisiones_como_va_${hoy}.xlsx`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
