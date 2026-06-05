import api from './index'

export const getReservaInfo        = ()           => api.get('/reserva/info')
export const getReservaInventario  = (search, page) => api.get('/reserva/inventario', { params: { search, page } })
export const getReservaStockLote   = (ids)        => api.get('/reserva/stock-lote', { params: { ids } })
export const addReservaStock       = (data)       => api.post('/reserva/entrada', data)
export const removeReservaStock    = (data)       => api.post('/reserva/salida', data)
export const getReservaMovimientos = (productoId) => api.get(`/reserva/movimientos/${productoId}`)
