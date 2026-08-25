import api from '@/api'

/**
 * Los que están en nómina (tienen sueldo). Con `{ sin_sueldo: 1 }` se piden
 * los que todavía no, para poder agregarlos.
 */
export const getEmpleados = (incluirInactivos = false, extra = {}) =>
  api.get('/nomina/empleados', {
    params: { ...(incluirInactivos ? { incluir_inactivos: 1 } : {}), ...extra },
  })

export const actualizarEmpleado = (id, payload) => api.patch(`/nomina/empleados/${id}`, payload)
export const eliminarEmpleado   = (id) => api.delete(`/nomina/empleados/${id}`)

/** Mismo sueldo/frecuencia para varios de una: cargar la fábrica uno por uno no escala. */
export const asignarEnLote = (payload) => api.patch('/nomina/empleados/lote', payload)
