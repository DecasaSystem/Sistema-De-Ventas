import api from '@/api'

export const getEmpleados = (incluirInactivos = false) =>
  api.get('/nomina/empleados', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })

export const actualizarEmpleado = (id, payload) => api.patch(`/nomina/empleados/${id}`, payload)
export const eliminarEmpleado   = (id) => api.delete(`/nomina/empleados/${id}`)

/** Mismo sueldo/frecuencia para varios de una: cargar la fábrica uno por uno no escala. */
export const asignarEnLote = (payload) => api.patch('/nomina/empleados/lote', payload)
