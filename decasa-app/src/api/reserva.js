import api from './index'

export const getReservaInfo           = ()              => api.get('/reserva/info')
// filtros: { orden, existencia } — igual que getInventario: van al servidor
// porque la lista llega paginada.
export const getReservaInventario     = (search, page, categoria, filtros = {}) => api.get('/reserva/inventario', { params: {
  search, page, categoria,
  ...(filtros.orden && filtros.orden !== 'stock_desc' ? { orden: filtros.orden } : {}),
  ...(filtros.existencia ? { existencia: filtros.existencia } : {}),
} })
export const getReservaStockLote      = (ids)           => api.get('/reserva/stock-lote', { params: { ids } })
// Total de la fábrica (o de una categoría): unidades y, a quien gestiona la
// Reserva, cuánta plata es a precio de venta. Sin categoría trae el reparto.
export const getReservaResumen        = (categoria, { silencioso = false } = {}) =>
  api.get('/reserva/resumen', { params: categoria ? { categoria } : {}, silencioso })
export const addReservaStock          = (data)          => api.post('/reserva/entrada', data)
export const addReservaVarianteStock  = (data)          => api.post('/reserva/variante-entrada', data)
export const removeReservaStock       = (data)          => api.post('/reserva/salida', data)
export const getReservaMovimientos    = (productoId)    => api.get(`/reserva/movimientos/${productoId}`)
