import api from './index'

export const getFichas              = (params = {}) => api.get('/fichas-tecnicas', { params })
export const getFicha               = (id) => api.get(`/fichas-tecnicas/${id}`)
export const crearFicha             = (data) => api.post('/fichas-tecnicas', data)
export const getMaterialesSugeridos = (search) => api.get('/fichas-tecnicas/materiales-sugeridos', { params: { search } })
export const actualizarItems        = (id, items, nombre) => api.patch(`/fichas-tecnicas/${id}/items`, { items, ...(nombre ? { nombre } : {}) })
export const reimportarFichas       = () => api.post('/fichas-tecnicas/reimportar')
