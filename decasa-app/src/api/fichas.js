import api from './index'

export const getFichas              = (params = {}) => api.get('/fichas-tecnicas', { params })
export const getFicha               = (id) => api.get(`/fichas-tecnicas/${id}`)
export const crearFicha             = (data) => api.post('/fichas-tecnicas', data)
export const getMaterialesSugeridos = (search) => api.get('/fichas-tecnicas/materiales-sugeridos', { params: { search } })
export const actualizarFicha        = (id, data) => api.patch(`/fichas-tecnicas/${id}/items`, data)
export const eliminarFicha          = (id) => api.delete(`/fichas-tecnicas/${id}`)
export const duplicarFicha          = (id, nombre) => api.post(`/fichas-tecnicas/${id}/duplicar`, nombre ? { nombre } : {})
export const vincularProducto       = (id, producto_id) => api.patch(`/fichas-tecnicas/${id}/producto`, { producto_id })
export const buscarProductos        = (search) => api.get('/productos', { params: { search, limit: 20 } })
