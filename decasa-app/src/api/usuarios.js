import api from './index'

export const getUsuarios = (params = {}) => api.get('/usuarios', { params })
export const getUsuario = (id) => api.get(`/usuarios/${id}`)
export const createUsuario = (data) => api.post('/usuarios', data)
export const updateUsuario = (id, data) => api.put(`/usuarios/${id}`, data)
export const toggleActivo = (id) => api.patch(`/usuarios/${id}/toggle-activo`)
// Qué grupos de avisos le llegan (Trabajadores → Notificaciones)
export const getNotificacionesUsuario = (id) => api.get(`/usuarios/${id}/notificaciones`)
export const setNotificacionesUsuario = (id, grupos) => api.put(`/usuarios/${id}/notificaciones`, { grupos })
// Qué le llega urgente (rojo y fijado en la campana). Ver NotificacionesUsuarioController.
export const setUrgentesUsuario = (id, urgentes) => api.put(`/usuarios/${id}/notificaciones`, { urgentes })
export const getMisUrgentes = () => api.get('/auth/mis-notificaciones')
export const setMisUrgentes = (urgentes) => api.put('/auth/mis-notificaciones', { urgentes })
export const resetPassword =(id, password) => api.post(`/usuarios/${id}/reset-password`, { password })
