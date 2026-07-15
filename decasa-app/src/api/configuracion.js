import api from './index'

export const getCostos       = ()      => api.get('/configuracion/costos')
export const guardarCostos   = (data)  => api.put('/configuracion/costos', data)
export const crearCargo      = (data)  => api.post('/configuracion/costos/cargos', data)
export const eliminarCargo   = (cargo) => api.delete(`/configuracion/costos/cargos/${cargo}`)
export const guardarFactorVenta = (factor) => api.put('/configuracion/costos/factor-venta', { factor_venta_sugerido: factor })
export const crearProceso    = (data)  => api.post('/configuracion/costos/procesos', data)
export const eliminarProceso = (id)    => api.delete(`/configuracion/costos/procesos/${id}`)
