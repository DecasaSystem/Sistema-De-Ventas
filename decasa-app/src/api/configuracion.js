import api from './index'

export const getCostos    = ()     => api.get('/configuracion/costos')
export const guardarCostos = (data) => api.put('/configuracion/costos', data)
