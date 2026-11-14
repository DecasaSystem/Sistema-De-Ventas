/**
 * El texto de un producto listo para pegarle a un cliente por WhatsApp
 * (Inventario → botón Copiar de la tarjeta). Es el atajo para cuando
 * preguntan "¿cuánto vale la cama Bali?": nombre, precio, medidas, cada
 * versión con su precio y la promoción si hay.
 *
 * Qué precio se copia lo escoge la persona (usuario, 2026-11-14: "tiene el
 * precio general y el de las variantes, copia el que se le da la gana y no
 * pregunta"). Antes, si las versiones valían distinto, salía "Desde" lo más
 * barato de todo, contando el precio general aunque ninguna versión se
 * vendiera a ese precio. Ahora, si el producto tiene versiones con precio,
 * Inventario pregunta (`opcionesParaCopiar`) y se copia:
 *  - 'general': solo el precio general del producto.
 *  - 'config:<id>' / 'talla:<id>': solo esa versión con su precio.
 *  - 'todo': el precio general y cada versión con el suyo.
 * Sin versiones con precio no se pregunta: va el precio general.
 *
 * Solo lleva lo que se le puede decir al cliente: nada de costos, stock en
 * números, apartados ni mínimos. Los asteriscos y las virgulillas son el
 * formato de WhatsApp (*negrita*, ~tachado~).
 *
 * Precios de las versiones:
 *  - `precio_adicional` de una opción (1.40, con baúl) es el PRECIO COMPLETO
 *    de esa versión; en 0, la versión vale lo del producto. Nunca se suma
 *    como recargo (así se corrigió en Inventario el 2026-10-16).
 *  - `precio_variante` de una talla es también su precio completo; vacío,
 *    vale lo del producto.
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

/** Las versiones con su precio de verdad (0 = el del producto). */
function versionesDe({ producto, grupos = [], variantes = [], esTalla = false }) {
  const base = Number(producto?.precio_base) || 0
  const gruposConPrecio = grupos
    .filter(g => g.items?.length)
    .map(g => ({
      nombre: g.tipo?.nombre ?? 'Opciones',
      items: g.items.map(o => ({
        clave: `config:${o.id}`,
        nombre: o.opcion_nombre,
        precio: Number(o.precio_adicional) > 0 ? Number(o.precio_adicional) : base,
        extra: { _config_id: o.id },
      })),
    }))
  // Tallas: cada una con su precio.
  const tallas = esTalla
    ? variantes.filter(v => v.medida).map(v => ({
        clave: `talla:${v.id}`,
        nombre: v.medida,
        precio: v.precio_variante != null && Number(v.precio_variante) > 0 ? Number(v.precio_variante) : base,
        extra: { variante_id: v.id },
      }))
    : []
  if (tallas.length) gruposConPrecio.push({ nombre: 'Tallas', items: tallas })
  return { base, grupos: gruposConPrecio }
}

/**
 * Lo que Inventario ofrece antes de copiar. `preguntar` es falso cuando el
 * producto no tiene versiones: ahí no hay nada que escoger.
 */
export function opcionesParaCopiar(datos) {
  const { base, grupos } = versionesDe(datos)
  return {
    preguntar: grupos.length > 0,
    base,
    grupos: grupos.map(g => ({
      nombre: g.nombre,
      items: g.items.map(i => ({ clave: i.clave, nombre: i.nombre, precio: i.precio })),
    })),
  }
}

/**
 * @param {object} p
 * @param {object} p.producto  el producto de la tarjeta (nombre, precio_base, medidas…)
 * @param {Array}  p.grupos    opciones con precio (`/variante-configs`): [{ tipo, items }]
 * @param {Array}  p.variantes telas/colores o tallas (`getVariantes`)
 * @param {boolean} p.esTalla  si las variantes son tallas (medida + precio) y no telas
 * @param {Array}  p.promos    las vigentes en la tienda que se mira (vacío en "todas")
 * @param {string} p.eleccion  'general' | 'todo' | 'config:<id>' | 'talla:<id>'
 */
export function textoDelProducto({ producto, grupos = [], variantes = [], esTalla = false, promos = [], eleccion = 'todo' }) {
  const pid  = producto?.id
  const { base, grupos: versiones } = versionesDe({ producto, grupos, variantes, esTalla })
  const promoDe = (extra) => promoParaItem(promos, { producto_id: pid, ...extra, precio_unitario: extra.precio })
  const hasta = new Set()    // hasta cuándo van las promociones que salen en el texto
  const anotar = (r) => { if (r.promo) hasta.add(String(r.promo.fecha_fin).slice(0, 10)); return r.texto }
  const precioDe = (precio, extra = {}) => anotar(precioConPromo(precio, promoDe({ precio, ...extra })))

  // La versión escogida (si se escogió una); si la clave ya no existe, todo.
  let escogida = null
  for (const g of versiones) {
    const i = g.items.find(x => x.clave === eleccion)
    if (i) escogida = { grupo: g.nombre, ...i }
  }
  const modo = escogida ? 'version' : (eleccion === 'general' ? 'general' : 'todo')

  const n = piezasPorJuego(producto)
  const lineas = [`*${String(producto?.nombre ?? '').trim().toUpperCase()}*`]

  if (modo === 'version') {
    lineas.push(`📌 ${escogida.grupo}: ${escogida.nombre}`)
    lineas.push(`💰 ${precioDe(escogida.precio, escogida.extra)}${n ? ' el juego' : ''}`)
  } else {
    // El general siempre dice que es el general cuando hay versiones con
    // otro precio, para que el cliente no lo confunda con el de una versión.
    const hayOtros = versiones.some(g => g.items.some(i => i.precio !== base))
    const etiqueta = modo === 'todo' && hayOtros ? 'Precio general: ' : ''
    lineas.push(`💰 ${etiqueta}${precioDe(base)}${n ? ' el juego' : ''}`)
  }

  if (n) lineas.push(`🪑 Juego de ${n} piezas · pieza suelta ${plata(precioPieza(producto))}`)
  if (producto?.medidas?.trim())     lineas.push(`📐 Medidas: ${producto.medidas.trim()}`)
  if (producto?.material?.trim())    lineas.push(`🪵 Material: ${producto.material.trim()}`)
  if (producto?.descripcion?.trim()) lineas.push('', producto.descripcion.trim())

  // Con "todo", cada versión con su precio (siempre: así no hay que adivinar
  // cuál vale qué).
  if (modo === 'todo') {
    for (const g of versiones) {
      lineas.push('', `*${g.nombre}*`)
      for (const o of g.items) lineas.push(`• ${o.nombre} — ${precioDe(o.precio, o.extra)}`)
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

  // Hasta cuándo va cada promoción que salió arriba.
  // El nombre de la promoción no se copia: lo pone el supervisor para
  // reconocerla ("Bali Unicentro nov") y no es para el cliente.
  if (hasta.size) {
    lineas.push('')
    for (const f of [...hasta].sort()) lineas.push(`🏷️ Precio de promoción hasta el ${fechaLarga(f)}`)
  }

  return lineas.join('\n').replace(/\n{3,}/g, '\n\n').trim()
}
