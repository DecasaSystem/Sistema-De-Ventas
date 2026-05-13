import api from './index'

export const getInventario  = (tiendaId, search = '', page = 1) =>
  api.get('/inventario', { params: { tienda_id: tiendaId, search, page } })

export const addStock = (data) => api.post('/inventario/entrada', data)

export const getVariantes = (productoId, tiendaId) =>
  api.get(`/productos/${productoId}/variantes`, { params: { tienda_id: tiendaId } })

export const crearVariante  = (productoId, data) =>
  api.post(`/productos/${productoId}/variantes`, data)

export const addStockVariante = (data) => api.post('/inventario/variantes/entrada', data)

export const getMovimientos = (productoId, tiendaId = null) =>
  api.get(`/inventario/${productoId}/movimientos`, { params: { tienda_id: tiendaId } })
