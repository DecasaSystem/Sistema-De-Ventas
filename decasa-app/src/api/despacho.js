import api from './index'

export const colaDespacho            = ()       => api.get('/despacho/cola')
export const asignados               = (params) => api.get('/despacho/asignados', { params })
export const asignar                 = (data)   => api.post('/despacho/asignar', data)
export const conductores             = ()       => api.get('/despacho/conductores')
export const historialDespacho       = (params) => api.get('/despacho/historial', { params })
export const detalleDespacho         = (id)     => api.get(`/despacho/${id}`)
export const misEntregas             = ()       => api.get('/despacho/mis-entregas')
export const historialMisEntregas    = (params) => api.get('/despacho/mis-entregas/historial', { params })
export const detalleEntrega          = (id)     => api.get(`/despacho/mis-entregas/${id}`)
export const registrarPagoEntrega    = (id, fd) => api.post(`/despacho/mis-entregas/${id}/pago`, fd, {
  headers: { 'Content-Type': 'multipart/form-data' },
})
export const marcarEntregado         = (id)     => api.patch(`/despacho/mis-entregas/${id}/entregar`)
export const despachoPorOrden        = (id)     => api.get(`/despacho/por-orden/${id}`)
