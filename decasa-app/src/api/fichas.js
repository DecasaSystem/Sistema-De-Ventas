import api from './index'

export const getFichas              = (params = {}) => api.get('/fichas-tecnicas', { params })
// Los productos de Inventario con su receta (o en espera) y las fichas sueltas
export const getRecetasProductos    = () => api.get('/fichas-tecnicas/productos')
export const getFicha               = (id) => api.get(`/fichas-tecnicas/${id}`)
export const crearFicha             = (data) => api.post('/fichas-tecnicas', data)
export const getMaterialesSugeridos = (search) => api.get('/fichas-tecnicas/materiales-sugeridos', { params: { search } })
export const actualizarFicha        = (id, data) => api.patch(`/fichas-tecnicas/${id}/items`, data)
export const eliminarFicha          = (id) => api.delete(`/fichas-tecnicas/${id}`)
// `destino` = { producto_id, variante_config_id }: la copia queda como receta de esa versión
export const duplicarFicha          = (id, nombre, destino = {}) => api.post(`/fichas-tecnicas/${id}/duplicar`, { ...(nombre ? { nombre } : {}), ...destino })
export const vincularProducto       = (id, producto_id, variante_config_id = null) =>
  api.patch(`/fichas-tecnicas/${id}/producto`, { producto_id, variante_config_id })
export const buscarProductos        = (search) => api.get('/productos', { params: { search, limit: 20 } })
