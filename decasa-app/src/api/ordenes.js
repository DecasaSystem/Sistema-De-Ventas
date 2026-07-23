import api from './index'

export const getOrdenes = (params = {}) => api.get('/ordenes', { params })
export const getOrden = (id) => api.get(`/ordenes/${id}`)
export const updateEstado = (id, estado) => api.patch(`/ordenes/${id}/estado`, { estado })
export const getPagos = (id) => api.get(`/ordenes/${id}/pagos`)
export const registrarPago = (id, data) => api.post(`/ordenes/${id}/pagos`, data)
export const editarPago = (pagoId, data) => api.patch(`/pagos/${pagoId}`, data)
export const descargarPdfOrden = (id) => api.get(`/ordenes/${id}/pdf`, { responseType: 'blob' })
export const reenviarCotizacion = (id, email = null) =>
  api.post(`/ordenes/${id}/reenviar-cotizacion`, email ? { email } : {})
export const asignarFechasEntrega = (id, items) =>
  api.patch(`/ordenes/${id}/fechas-entrega`, { items })
export const editarOrden = (id, data) => api.patch(`/ordenes/${id}`, data)
export const confirmarCotizacion = (id, data) => api.post(`/ordenes/${id}/confirmar-cotizacion`, data)
export const completarBorrador = (id, data) => api.post(`/ordenes/${id}/completar-borrador`, data)
export const buscarProductos = (search = '', tiendaId = null) =>
  api.get('/productos', { params: { search, ...(tiendaId ? { tienda_id: tiendaId } : {}) } })
export const getTiendas = () => api.get('/tiendas')
