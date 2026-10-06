import api from './index'

export const getMateriales       = (search = '') => api.get('/materiales', { params: { search } })
export const crearMaterial       = (data) => api.post('/materiales', data)
export const actualizarMaterial  = (id, data) => api.patch(`/materiales/${id}`, data)
export const importarMateriales  = () => api.post('/materiales/importar')
export const getHistorialMaterial = (id) => api.get(`/materiales/${id}/historial`)
