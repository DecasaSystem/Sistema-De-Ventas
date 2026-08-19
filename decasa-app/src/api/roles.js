import api from '@/api'

export const getRoles = (incluirInactivos = false) =>
  api.get('/roles', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })

export const crearRol      = (payload) => api.post('/roles', payload)
export const actualizarRol = (id, payload) => api.patch(`/roles/${id}`, payload)
export const eliminarRol   = (id) => api.delete(`/roles/${id}`)
