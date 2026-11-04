/**
 * El texto de un producto listo para pegarle a un cliente por WhatsApp
 * (Inventario → botón Copiar de la tarjeta). Es el atajo para cuando
 * preguntan "¿cuánto vale la cama Bali?": nombre, precio, medidas, cada
 * versión con su precio y la promoción si hay.
 *
 * Solo lleva lo que se le puede decir al cliente: nada de costos, stock en
 * números, apartados ni mínimos. Los asteriscos y las virgulillas son el
 * formato de WhatsApp (*negrita*, ~tachado~).
 *
 * Precios de las versiones:
 *  - `precio_adicional` de una opción (1.40, con baúl) es el PRECIO COMPLETO
 *    de esa versión; en 0, la versión vale lo del producto. Nunca se suma
 *    como recargo (así se corrigió en Inventario el 2026-10-16).
 *  - `precio_variante` de una tela o talla es también su precio completo;
 *    vacío, vale lo del producto.
 * La promoción se busca con la misma regla de Nueva orden (`promoParaItem`):
 * la de esa versión gana sobre la del producto entero.
 */
import { formatearPesos } from '@/utils/pesos'
import { promoParaItem, rebajaDePromo } from '@/utils/promociones'
import { piezasPorJuego, precioPieza } from '@/utils/juegos'

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']

const plata = (n) => `$${formatearPesos(n)}`

/** "30 de noviembre": el cliente lee la fecha completa, no "30 nov". */
function fechaLarga(fecha) {
  if (!fecha) return ''
  const [a, m, d] = String(fecha).slice(0, 10).split('-').map(Number)
  return `${d} de ${MESES[m - 1]}${a !== new Date().getFullYear() ? ' de ' + a : ''}`
}

/** El precio con su promoción, si la tiene: "~$1.890.000~ *$1.590.000*". */
function precioConPromo(lista, promo) {
  const rebaja = promo ? rebajaDePromo(promo, { precio_unitario: lista }) : 0
  if (rebaja <= 0) return { texto: plata(lista), promo: null, final: lista }
  return { texto: `~${plata(lista)}~ *${plata(lista - rebaja)}*`, promo, final: lista - rebaja }
}

/**
 * @param {object} p
 * @param {object} p.producto  el producto de la tarjeta (nombre, precio_base, medidas…)
 * @param {Array}  p.grupos    opciones con precio (`/variante-configs`): [{ tipo, items }]
 * @param {Array}  p.variantes telas/colores o tallas (`getVariantes`)
 * @param {boolean} p.esTalla  si las variantes son tallas (medida + precio) y no telas
 * @param {Array}  p.promos    las vigentes en la tienda que se mira (vacío en "todas")
 */
export function textoDelProducto({ producto, grupos = [], variantes = [], esTalla = false, promos = [] }) {
  const pid  = producto?.id
  const base = Number(producto?.precio_base) || 0
  const promoDe = (extra) => promoParaItem(promos, { producto_id: pid, precio_unitario: extra.precio, ...extra })
  const finales = []         // lo que de verdad paga el cliente por cada versión
  const hasta = new Set()    // hasta cuándo van las promociones que salen en el texto
  const anotar = (r) => { if (r.promo) hasta.add(String(r.promo.fecha_fin).slice(0, 10)); return r.texto }
  // La versión lleva su precio si las versiones valen distinto, o si ella
  // sola tiene promoción (la de la 1.40 aunque todas valgan lo mismo).
  const sufijo = (precio, extra) => {
    const r = precioConPromo(precio, promoDe({ precio, ...extra }))
    finales.push(r.final)
    return (varian || r.promo) ? ` — ${anotar(r)}` : ''
  }

  // Cada opción con su precio de verdad (0 = el del producto).
  const gruposConPrecio = grupos
    .filter(g => g.items?.length)
    .map(g => ({
      nombre: g.tipo?.nombre ?? 'Opciones',
      items: g.items.map(o => ({
        nombre: o.opcion_nombre,
        precio: Number(o.precio_adicional) > 0 ? Number(o.precio_adicional) : base,
        config_id: o.id,
      })),
    }))

  // Tallas: cada una con su precio. Telas: los nombres (las combinaciones con
  // una medida se repiten por cada medida: se cuentan una vez).
  const tallas = esTalla
    ? variantes.filter(v => v.medida).map(v => ({
        nombre: v.medida,
        precio: v.precio_variante != null && Number(v.precio_variante) > 0 ? Number(v.precio_variante) : base,
        variante_id: v.id,
      }))
    : []

  const precios = [base, ...gruposConPrecio.flatMap(g => g.items.map(i => i.precio)), ...tallas.map(t => t.precio)]
  const varian  = new Set(precios).size > 1

  const lineas = [`*${String(producto?.nombre ?? '').trim().toUpperCase()}*`]

  const n = piezasPorJuego(producto)

  // Un precio solo si todas las versiones valen lo mismo; si no, "desde" lo
  // más barato (con promoción incluida), que se llena al final.
  const filaDesde = lineas.length
  if (varian) {
    lineas.push('')
  } else {
    lineas.push(`💰 ${anotar(precioConPromo(base, promoDe({ precio: base })))}${n ? ' el juego' : ''}`)
  }

  if (n) lineas.push(`🪑 Juego de ${n} piezas · pieza suelta ${plata(precioPieza(producto))}`)
  if (producto?.medidas?.trim())     lineas.push(`📐 Medidas: ${producto.medidas.trim()}`)
  if (producto?.material?.trim())    lineas.push(`🪵 Material: ${producto.material.trim()}`)
  if (producto?.descripcion?.trim()) lineas.push('', producto.descripcion.trim())

  // Cada versión; con su precio si valen distinto o si tiene promoción.
  for (const g of gruposConPrecio) {
    lineas.push('', `*${g.nombre}*`)
    for (const o of g.items) {
      lineas.push(`• ${o.nombre}${sufijo(o.precio, { _config_id: o.config_id })}`)
    }
  }

  if (tallas.length) {
    lineas.push('', '*Tallas*')
    for (const t of tallas) {
      lineas.push(`• ${t.nombre}${sufijo(t.precio, { variante_id: t.variante_id })}`)
    }
  }

  // Telas que hay para entregar: agrupadas por tipo ("Chenille: Gris, Beige").
  if (!esTalla) {
    const porTipo = new Map()
    for (const v of variantes) {
      if ((v.stock_libre ?? 0) <= 0) continue
      const color = v.nombre_color || v.marca_tela || v.marca
      if (!color) continue
      const tipo = (v.nombre_color ? (v.marca_tela || v.marca) : '') || 'Otras'
      if (!porTipo.has(tipo)) porTipo.set(tipo, new Set())
      porTipo.get(tipo).add(color)
    }
    if (porTipo.size) {
      lineas.push('', '*Telas disponibles*')
      for (const [tipo, colores] of porTipo) {
        const lista = [...colores].sort((a, b) => a.localeCompare(b, 'es')).join(', ')
        lineas.push(porTipo.size === 1 && tipo === 'Otras' ? `• ${lista}` : `• ${tipo}: ${lista}`)
      }
    }
  }

  if (varian) lineas[filaDesde] = `💰 Desde ${plata(Math.min(...(finales.length ? finales : precios)))}`

  // Hasta cuándo va cada promoción que salió arriba.
  // El nombre de la promoción no se copia: lo pone el supervisor para
  // reconocerla ("Bali Unicentro nov") y no es para el cliente.
  if (hasta.size) {
    lineas.push('')
    for (const f of [...hasta].sort()) lineas.push(`🏷️ Precio de promoción hasta el ${fechaLarga(f)}`)
  }

  return lineas.join('\n').replace(/\n{3,}/g, '\n\n').trim()
}
