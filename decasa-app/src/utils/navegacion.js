import { COLOMBIA } from '@/data/colombia'

/**
 * Cómo llegar a la casa del cliente: el destino armado para que Waze y Google
 * Maps lo encuentren, y el teléfono para llamar si no lo encuentran.
 *
 * Una dirección colombiana sola ("Cra 12 # 34-56") existe en casi todas las
 * ciudades del país: sin la ciudad, el mapa manda al conductor a cualquiera.
 * Por eso se completa siempre con la ciudad y "Colombia".
 */

const sinTildes = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

/** ¿El texto ya nombra esa ciudad, como palabra completa? */
function mencionaCiudad(texto, ciudad) {
  const c = sinTildes(ciudad).trim()
  if (!c) return false
  const escapada = c.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  return new RegExp(`(^|[^a-z])${escapada}([^a-z]|$)`).test(sinTildes(texto))
}

/**
 * Las ciudades que, si la dirección ya las nombra, no hay que pisar con la de
 * la tienda: si dice "Calarcá" o "Pereira", pegarle "Armenia" encima manda
 * al conductor a otra parte.
 *
 * Son los municipios del departamento de la tienda —los repartos son de la
 * zona— y las capitales de todo el país (la primera de cada lista).
 *
 * Hay un Armenia en el Quindío y otro en Antioquia: manda el departamento del
 * que la ciudad es capital. Tomar los dos haría que un barrio "Bello
 * Horizonte" pareciera el municipio de Bello y la dirección se quedara sin
 * ciudad.
 */
function municipiosDeLaZona(ciudadTienda) {
  const c = sinTildes(ciudadTienda).trim()
  if (!c) return []
  const capitales = COLOMBIA.map(d => d.municipios[0])
  const comoCapital = COLOMBIA.filter(d => sinTildes(d.municipios[0]) === c)
  const deptos = comoCapital.length
    ? comoCapital
    : COLOMBIA.filter(d => d.municipios.some(m => sinTildes(m) === c))
  return [ciudadTienda, ...capitales, ...deptos.flatMap(d => d.municipios)]
}

/**
 * El destino de una orden, listo para el mapa, o null si no hay dirección.
 *
 * Primero la dirección de envío de la orden, que es la que el vendedor
 * escribió para esta entrega (con su ciudad y departamento). Si no hay, la
 * del cliente, completada con la ciudad de la tienda de la orden salvo que ya
 * nombre un municipio de la zona.
 */
export function destinoDe(orden) {
  if (!orden) return null

  const envio = (orden.direccion_envio ?? '').trim()
  if (envio) {
    const partes = [envio]
    if (orden.ciudad_envio && !mencionaCiudad(envio, orden.ciudad_envio)) partes.push(orden.ciudad_envio)
    if (orden.departamento_envio && !mencionaCiudad(envio, orden.departamento_envio)) partes.push(orden.departamento_envio)
    partes.push('Colombia')
    return partes.join(', ')
  }

  const delCliente = (orden.cliente?.direccion ?? '').trim()
  if (!delCliente) return null

  const partes = [delCliente]
  const ciudadTienda = orden.tienda?.ciudad
  if (ciudadTienda && !municipiosDeLaZona(ciudadTienda).some(m => mencionaCiudad(delCliente, m))) {
    partes.push(ciudadTienda)
  }
  partes.push('Colombia')
  return partes.join(', ')
}

/** Abre Waze ya navegando hacia el destino (la app si está instalada). */
export function linkWaze(destino) {
  return destino ? `https://waze.com/ul?q=${encodeURIComponent(destino)}&navigate=yes` : null
}

/** Abre Google Maps con la ruta en carro hasta el destino. */
export function linkGoogleMaps(destino) {
  return destino
    ? `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destino)}&travelmode=driving`
    : null
}

/**
 * Para llamar tocando el número. Un celular colombiano de 10 dígitos va con
 * +57 para que marque igual desde cualquier teléfono.
 */
export function linkTel(telefono) {
  const digitos = String(telefono ?? '').replace(/[^\d+]/g, '')
  if (!digitos) return null
  if (/^3\d{9}$/.test(digitos)) return `tel:+57${digitos}`
  return `tel:${digitos}`
}
