/**
 * Vender como módulos: lo mismo que App\Support\ModulosDeVenta en el backend.
 * Cada uno se prende por persona en Trabajadores, sea cual sea su rol.
 */
export const MODULOS_DE_VENTA = [
  { campo: 'acceso_nueva_orden',     nombre: 'Nueva orden',     ayuda: 'Crear órdenes de venta.' },
  { campo: 'acceso_ordenes',         nombre: 'Órdenes',         ayuda: 'Ver la lista de órdenes y seguirlas.' },
  { campo: 'acceso_clientes',        nombre: 'Clientes',        ayuda: 'El módulo de clientes. Quien vende o cotiza igual escoge y crea el cliente ahí mismo.' },
  { campo: 'acceso_cotizaciones',    nombre: 'Cotizaciones',    ayuda: 'Hacer y mandar cotizaciones.' },
  { campo: 'acceso_caja',            nombre: 'Caja',            ayuda: 'La plata de la tienda (o su caja propia).' },
  { campo: 'acceso_consultas_costo', nombre: 'Consultar costo', ayuda: 'Pedir el precio de algo personalizado.' },
  { campo: 'acceso_estadisticas',    nombre: 'Estadísticas',    ayuda: 'Sus ventas y metas.' },
]

/**
 * Los que trae un rol cuando no se le han escogido (igual que
 * ModulosDeVenta::DEL_ARQUETIPO). Vendedor sin Caja: va aparte.
 */
const DEL_ARQUETIPO = {
  vendedor:   ['acceso_nueva_orden', 'acceso_ordenes', 'acceso_clientes', 'acceso_cotizaciones', 'acceso_consultas_costo', 'acceso_estadisticas'],
  supervisor: MODULOS_DE_VENTA.map(m => m.campo),
}

/** Los módulos de venta que trae un rol (lista de campos). */
export const modulosDelRol = (rol) =>
  Array.isArray(rol?.modulos_venta) ? rol.modulos_venta : (DEL_ARQUETIPO[rol?.arquetipo] ?? [])

/** Con qué arranca alguien nuevo con ese rol: campo => bool. */
export const modulosDeVentaDeArranque = (rol) => {
  const del = modulosDelRol(rol)
  return Object.fromEntries(MODULOS_DE_VENTA.map(m => [m.campo, del.includes(m.campo)]))
}
