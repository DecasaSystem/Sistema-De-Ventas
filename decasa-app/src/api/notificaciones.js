import api from './index'

export const notificacionesApi = {
  listar:       (antesDe) => api.get('/notificaciones', { params: antesDe ? { antes_de: antesDe } : {} }),
  marcarLeida:  (id) => api.patch(`/notificaciones/${id}/leida`),
  marcarTodas:  ()   => api.patch('/notificaciones/leer-todas'),
  eliminar:     (id) => api.delete(`/notificaciones/${id}`),
  eliminarTodas: ()  => api.delete('/notificaciones/todas'),
}
