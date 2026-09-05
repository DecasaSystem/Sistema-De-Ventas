import api from './index'

export const getInventario  = (tiendaId, search = '', page = 1, categoria = '', perPage = null) =>
  api.get('/inventario', { params: { tienda_id: tiendaId, search, page, ...(categoria ? { categoria } : {}), ...(perPage ? { per_page: perPage } : {}) } })

export const getDesgloseVariantes = (tiendaId) =>
  api.get('/inventario/desglose-variantes', { params: { tienda_id: tiendaId } })

// El total de una categoría (y, en "todas las tiendas", cuánto hay en cada
// una). Va aparte del listado porque este llega paginado y sumarlo en el
// front daría un total incompleto mientras no se haya cargado todo.
export const getResumenCategoria = (tiendaId, categoria) =>
  api.get('/inventario/resumen-categoria', { params: { tienda_id: tiendaId, categoria } })

export const addStock    = (data) => api.post('/inventario/entrada', data)
export const removeStock = (data) => api.post('/inventario/salida',  data)

// `silencioso` para cuando se pide una por cada tarjeta ya pintada: cada tarjeta
// muestra su propio aviso, y encender además la S global hacía que la pantalla
// pareciera cargar dos veces seguidas.
export const getVariantes = (productoId, tiendaId, silencioso = false) =>
  api.get(`/productos/${productoId}/variantes`, { params: { tienda_id: tiendaId }, silencioso })

export const crearVariante  = (productoId, data) =>
  api.post(`/productos/${productoId}/variantes`, data)

export const getVarianteUso = (productoId, varianteId) =>
  api.get(`/productos/${productoId}/variantes/${varianteId}/uso`)

export const eliminarVariante = (productoId, varianteId) =>
  api.delete(`/productos/${productoId}/variantes/${varianteId}`)

export const addStockVariante = (data) => api.post('/inventario/variantes/entrada', data)

export const getMovimientos = (productoId, tiendaId = null) =>
  api.get(`/inventario/${productoId}/movimientos`, { params: { tienda_id: tiendaId } })
