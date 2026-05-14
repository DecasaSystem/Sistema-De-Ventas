import api from './index'

export const notificacionesApi = {
  listar:       ()   => api.get('/notificaciones'),
  marcarLeida:  (id) => api.patch(`/notificaciones/${id}/leida`),
  marcarTodas:  ()   => api.patch('/notificaciones/leer-todas'),
  eliminar:     (id) => api.delete(`/notificaciones/${id}`),
  eliminarTodas: ()  => api.delete('/notificaciones/todas'),
}
