import api from '@/api'

export const getSueldos        = (incluirInactivos = false) => api.get('/nomina/sueldos', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })
export const crearSueldo       = (payload) => api.post('/nomina/sueldos', payload)
export const actualizarSueldo  = (id, payload) => api.patch(`/nomina/sueldos/${id}`, payload)
export const eliminarSueldo    = (id) => api.delete(`/nomina/sueldos/${id}`)

// Los ciclos no se crean: el backend los calcula del calendario y devuelve
// lo que ya está cerrado y sin cobrar.
export const getPagosPendientes = () => api.get('/nomina/pagos/pendientes')
export const pagar              = (empleadoId, fechaInicio, observaciones = null) =>
  api.post('/nomina/pagos', { empleado_id: empleadoId, fecha_inicio: fechaInicio, observaciones })
// Se manda la lista exacta que se tiene en pantalla, no un "paga todo".
export const pagarLote          = (pagos) => api.post('/nomina/pagos/lote', { pagos })
export const getHistorialPagos  = (params = {}) => api.get('/nomina/pagos', { params })
export const deshacerPago       = (id) => api.delete(`/nomina/pagos/${id}`)

export const getAusencias      = (params = {}) => api.get('/nomina/ausencias', { params })
export const crearAusencia     = (payload) => api.post('/nomina/ausencias', payload)
export const eliminarAusencia  = (id) => api.delete(`/nomina/ausencias/${id}`)

export const getAjustes        = (params = {}) => api.get('/nomina/ajustes', { params })
export const crearAjuste       = (payload) => api.post('/nomina/ajustes', payload)
export const eliminarAjuste    = (id) => api.delete(`/nomina/ajustes/${id}`)
