import api from '@/api'

export const getPerfilesProduccion = (incluirInactivos = false) =>
  api.get('/perfiles-produccion', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })

export const crearPerfilProduccion      = (payload) => api.post('/perfiles-produccion', payload)
export const actualizarPerfilProduccion = (id, payload) => api.patch(`/perfiles-produccion/${id}`, payload)
export const eliminarPerfilProduccion   = (id) => api.delete(`/perfiles-produccion/${id}`)
