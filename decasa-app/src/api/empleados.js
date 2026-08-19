import api from '@/api'

export const getEmpleados = (incluirInactivos = false) =>
  api.get('/nomina/empleados', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })

export const crearEmpleado      = (payload) => api.post('/nomina/empleados', payload)
export const actualizarEmpleado = (id, payload) => api.patch(`/nomina/empleados/${id}`, payload)
export const eliminarEmpleado   = (id) => api.delete(`/nomina/empleados/${id}`)
