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
