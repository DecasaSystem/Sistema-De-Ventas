import api from '@/api'

export const getSueldos        = (incluirInactivos = false) => api.get('/nomina/sueldos', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })
export const crearSueldo       = (payload) => api.post('/nomina/sueldos', payload)
export const actualizarSueldo  = (id, payload) => api.patch(`/nomina/sueldos/${id}`, payload)
export const eliminarSueldo    = (id) => api.delete(`/nomina/sueldos/${id}`)

export const getPeriodos       = () => api.get('/nomina/periodos')
export const getPeriodo        = (id) => api.get(`/nomina/periodos/${id}`)
export const crearPeriodo      = (payload) => api.post('/nomina/periodos', payload)
export const actualizarPeriodo = (id, payload) => api.patch(`/nomina/periodos/${id}`, payload)
export const eliminarPeriodo   = (id) => api.delete(`/nomina/periodos/${id}`)
export const marcarPeriodoPagado = (id) => api.post(`/nomina/periodos/${id}/pagar`)
export const agregarEmpleadoAPeriodo = (id, empleadoId) => api.post(`/nomina/periodos/${id}/empleados`, { empleado_id: empleadoId })

export const actualizarItem = (id, payload) => api.patch(`/nomina/items/${id}`, payload)
export const eliminarItem   = (id) => api.delete(`/nomina/items/${id}`)

export const crearAjuste   = (itemId, payload) => api.post(`/nomina/items/${itemId}/ajustes`, payload)
export const eliminarAjuste = (id) => api.delete(`/nomina/ajustes/${id}`)

export const getAusencias      = (params = {}) => api.get('/nomina/ausencias', { params })
export const crearAusencia     = (payload) => api.post('/nomina/ausencias', payload)
export const eliminarAusencia  = (id) => api.delete(`/nomina/ausencias/${id}`)
