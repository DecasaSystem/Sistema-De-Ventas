// Lo que el cliente ve de su pedido antes de firmar a distancia.
//
// Lo arman Nueva orden (la orden todavía no existe) y el detalle de una orden
// ya creada que quedó sin firma, con la misma forma, para que la página del
// cliente (FirmarAnexoView) pinte igual las dos. El servidor no confía en
// esto para la plata: con las líneas de la orden saca una huella, y si la
// orden cambia después de firmar, la firma ya no vale.

import { SPECS_TEMPLATES, resolverCategoria } from '@/constants/specsConfig'

const ETIQUETAS = {
  marca: 'Marca', tela: 'Tela', color: 'Color', medidas: 'Medidas',
  acabado: 'Acabado', descripcion: 'Descripción', descripcion_trabajo: 'Trabajo',
  material: 'Material', color_material: 'Color/acabado',
  largo_cm: 'Largo', ancho_cm: 'Ancho', alto_cm: 'Alto',
  variante_marca: 'Marca', variante_color: 'Color', tela_original: 'Tela actual',
  trabajo: 'Trabajo en fábrica',
}

// Marcas internas que no le dicen nada al cliente.
const INTERNAS = new Set(['retapizar', 'notas', 'trabajo'])

/** Las especificaciones de lo personalizado, con nombres que se entienden. */
export function specsParaCliente(specs, nombre, categoria) {
  if (!specs || typeof specs !== 'object') return []
  const template = SPECS_TEMPLATES[resolverCategoria(nombre, categoria)] ?? SPECS_TEMPLATES['generico']
  const vistos = new Set()
  const partes = []
  const escalar = v => (typeof v === 'string' && v.trim() !== '') || (typeof v === 'number' && Number.isFinite(v))

  for (const campo of template?.campos ?? []) {
    const v = specs[campo.key]
    if (!escalar(v)) continue
    vistos.add(campo.key)
    partes.push({ label: campo.label, value: String(v) })
  }
  for (const [k, v] of Object.entries(specs)) {
    if (vistos.has(k) || INTERNAS.has(k) || k.startsWith('_') || !escalar(v)) continue
    partes.push({ label: ETIQUETAS[k] ?? k.replace(/_/g, ' '), value: String(v) })
  }
  if (escalar(specs.notas)) partes.push({ label: 'Notas', value: String(specs.notas) })

  return partes.slice(0, 40)
}

/** Solo direcciones https (Cloudinary): nada de blobs del teléfono ni otras cosas. */
export function soloHttps(urls) {
  return (urls ?? []).filter(u => typeof u === 'string' && u.startsWith('https://')).slice(0, 10)
}

/**
 * Qué es cada producto, dicho para el cliente. Son las mismas clases que
 * `OrdenItem::tipo_item` en el servidor; el de inventario no lleva etiqueta
 * (es lo normal).
 */
const TRABAJOS = { tela: 'Cambio de tela', color: 'Cambio de color', arreglo: 'Arreglo' }

export function tipoParaCliente(tipo, trabajo = null) {
  switch (tipo) {
    case 'fabricar':        return 'Se fabrica para ti'
    case 'personalizado':   return 'Personalizado: se fabrica con tus especificaciones'
    case 'diseno_especial': return 'Diseño especial: se fabrica a la medida'
    case 'restauracion':    return 'Restauración de tu mueble'
    case 'retapizar':       return `${trabajo || 'Cambio de tela'} en nuestra fábrica`
    case 'producto_unico':  return 'Mueble único: ya está hecho y se entrega como está'
    default:                return null
  }
}

/** El tipo de un producto del carrito de Nueva orden (mismas reglas que el servidor). */
export function tipoDeItemCarrito(i) {
  if (i._es_restauracion) return 'restauracion'
  if (i._producto_unico)  return 'producto_unico'
  if (i._retapizar)       return 'retapizar'
  if (!i.es_personalizado) return 'catalogo'
  if (!i.producto_id)     return 'diseno_especial'
  if (i._fabricar_pedido) return 'fabricar'
  return 'personalizado'
}

export function trabajoTexto(clave) {
  return TRABAJOS[clave] ?? null
}
