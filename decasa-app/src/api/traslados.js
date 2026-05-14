import api from './index'

export const getStockTienda   = (tiendaId) => api.get(`/inventario/traslados/stock-tienda/${tiendaId}`)
export const crearTraslado    = (data)     => api.post('/inventario/traslados', data)
export const getTraslados     = ()         => api.get('/inventario/traslados')
