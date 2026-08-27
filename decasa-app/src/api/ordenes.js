import api from './index'

export const getOrdenes = (params = {}) => api.get('/ordenes', { params })
export const getOrden = (id) => api.get(`/ordenes/${id}`)
export const updateEstado = (id, estado) => api.patch(`/ordenes/${id}/estado`, { estado })
export const getPagos = (id) => api.get(`/ordenes/${id}/pagos`)
export const registrarPago = (id, data) => api.post(`/ordenes/${id}/pagos`, data)
export const editarPago = (pagoId, data) => api.patch(`/pagos/${pagoId}`, data)
export const descargarPdfOrden = (id) => api.get(`/ordenes/${id}/pdf`, { responseType: 'blob' })

/** Acta de satisfacción firmada por quien recibió la entrega. */
export const descargarActaEntrega = (id) => api.get(`/ordenes/${id}/acta-entrega`, { responseType: 'blob' })
export const reenviarCotizacion = (id, email = null) =>
  api.post(`/ordenes/${id}/reenviar-cotizacion`, email ? { email } : {})
export const asignarFechasEntrega = (id, items) =>
  api.patch(`/ordenes/${id}/fechas-entrega`, { items })
export const editarOrden = (id, data) => api.patch(`/ordenes/${id}`, data)
export const confirmarCotizacion = (id, data) => api.post(`/ordenes/${id}/confirmar-cotizacion`, data)
export const completarBorrador = (id, data) => api.post(`/ordenes/${id}/completar-borrador`, data)
export const eliminarBorrador = (id) => api.delete(`/ordenes/${id}`)
export const buscarProductos = (search = '', tiendaId = null) =>
  api.get('/productos', { params: { search, ...(tiendaId ? { tienda_id: tiendaId } : {}) } })
export const getTiendas = () => api.get('/tiendas')

// Fijar una orden para tenerla de primeras. Es un marcador personal:
// cada quien arma el suyo y no ve el de los demás.
export const fijarOrden  = (id) => api.post(`/ordenes/${id}/fijar`)
export const quitarFijada = (id) => api.delete(`/ordenes/${id}/fijar`)

// Deshace una entrega marcada por error y devuelve el producto al inventario.
// Solo supervisor, y no aplica a las que entregó un conductor (tienen acta).
export const revertirEntrega = (id, motivo) =>
  api.patch(`/ordenes/${id}/revertir-entrega`, { motivo })

// El cliente devuelve algo ya entregado y lo cambia por otra cosa. Reabre la
// orden: lo devuelto deja de cobrarse, lo que ya pagó queda a su favor y el
// producto nuevo se agrega despues con la edicion normal.
export const cambiarProductoEntregado = (id, payload) =>
  api.post(`/ordenes/${id}/cambiar-producto`, payload)

// ── Numeración (solo supervisor) ─────────────────────────────────────────────
// Convertir una orden a serie (FV2/R) y, si se pide, correr los consecutivos
// de las siguientes para no dejar el hueco. Se previsualiza antes de aplicar:
// correr números no se deshace con un botón.
export const previsualizarNumeracion = (id, serie, correr) =>
  api.get(`/ordenes/${id}/numeracion`, { params: { serie, correr: correr ? 1 : 0 } })
export const convertirSerie = (id, payload) =>
  api.post(`/ordenes/${id}/numeracion/convertir`, payload)
export const cambiarNumeroOrden = (id, numero) =>
  api.patch(`/ordenes/${id}/numeracion`, { numero_orden: numero })
