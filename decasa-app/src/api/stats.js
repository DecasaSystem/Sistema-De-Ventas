import api from './index.js'

export const getPanel       = (params) => api.get('/stats/panel', { params })
export const getTendencia   = (params) => api.get('/stats/tendencia', { params })
export const getProductos   = (params) => api.get('/stats/productos', { params })
export const getCartera     = (params) => api.get('/stats/cartera',  {params })
export const getStatsMe     = (params) => api.get('/stats/vendedores/me', { params })
// Las tarjetas de tienda (como en Reportes) pero solo de las tiendas de quien pregunta.
export const getMisTiendas  = (params) => api.get('/stats/mis-tiendas', { params })
export const getStatsTiendas    = (params) => api.get('/stats/tiendas', { params })
export const getStatsVendedores = (params) => api.get('/stats/vendedores', { params })
export const getStatsVendedor   = (id, params) => api.get(`/stats/vendedor/${id}`, { params })
export const getStatsCategorias = (params) => api.get('/stats/categorias', { params })
export const getInteresados        = (params) => api.get('/reportes/interesados', { params })
export const getStatsConductores   = (params) => api.get('/stats/conductores', { params })
export const getMetricasRedes      = (params) => api.get('/redes/metricas', { params })
