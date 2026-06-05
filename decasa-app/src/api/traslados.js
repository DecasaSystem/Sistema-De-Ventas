import api from './index'

export const getStockTienda          = (tiendaId) => api.get(`/inventario/traslados/stock-tienda/${tiendaId}`)
export const crearTraslado           = (data)     => api.post('/inventario/traslados', data)
export const getTraslados            = ()         => api.get('/inventario/traslados')
export const getTrasladosPendientes  = ()         => api.get('/inventario/traslados/pendientes')
export const aceptarTraslado         = (id, payload = {}) => api.patch(`/inventario/traslados/${id}/aceptar`, payload)
export const rechazarTraslado        = (id, notas = null) => api.patch(`/inventario/traslados/${id}/rechazar`, { notas })
