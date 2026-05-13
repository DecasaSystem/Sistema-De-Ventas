import api from './index'

export const getUsuarios = (params = {}) => api.get('/usuarios', { params })
export const getUsuario = (id) => api.get(`/usuarios/${id}`)
export const createUsuario = (data) => api.post('/usuarios', data)
export const updateUsuario = (id, data) => api.put(`/usuarios/${id}`, data)
export const toggleActivo = (id) => api.patch(`/usuarios/${id}/toggle-activo`)
export const resetPassword = (id, password) => api.post(`/usuarios/${id}/reset-password`, { password })
