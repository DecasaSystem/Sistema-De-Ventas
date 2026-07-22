import api from './index'

export const getInventario  = (tiendaId, search = '', page = 1, categoria = '') =>
  api.get('/inventario', { params: { tienda_id: tiendaId, search, page, ...(categoria ? { categoria } : {}) } })

export const addStock    = (data) => api.post('/inventario/entrada', data)
export const removeStock = (data) => api.post('/inventario/salida',  data)

export const getVariantes = (productoId, tiendaId) =>
  api.get(`/productos/${productoId}/variantes`, { params: { tienda_id: tiendaId } })

export const crearVariante  = (productoId, data) =>
  api.post(`/productos/${productoId}/variantes`, data)

export const getVarianteUso = (productoId, varianteId) =>
  api.get(`/productos/${productoId}/variantes/${varianteId}/uso`)

export const eliminarVariante = (productoId, varianteId) =>
  api.delete(`/productos/${productoId}/variantes/${varianteId}`)

export const addStockVariante = (data) => api.post('/inventario/variantes/entrada', data)

export const getMovimientos = (productoId, tiendaId = null) =>
  api.get(`/inventario/${productoId}/movimientos`, { params: { tienda_id: tiendaId } })
