/**
 * Los tres tipos de orden con los que Reportes parte la plata: venta,
 * restauración y FV2. La regla de cuál es cuál vive en el servidor
 * (Orden::sqlTipo); aquí solo el nombre y el color.
 *
 * Colores de una paleta validada para daltonismo y contraste, en orden fijo:
 * "azul" significa siempre ventas, en el desglose y en la cartera.
 */
export const TIPOS_ORDEN = [
  { clave: 'venta',        label: 'Ventas',          corto: 'Venta',        color: '#2a78d6' },
  { clave: 'restauracion', label: 'Restauraciones',  corto: 'Restauración', color: '#eb6834' },
  { clave: 'fv2',          label: 'FV2 (descuento)', corto: 'FV2',          color: '#1baf7a' },
]

export const TIPO_ORDEN = Object.fromEntries(TIPOS_ORDEN.map(t => [t.clave, t]))
