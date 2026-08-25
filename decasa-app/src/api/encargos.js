import api from '@/api'

// La lista de quién responde por qué, con el estado de revista de cada uno.
export const getTrabajadores = (incluirInactivos = false) =>
  api.get('/encargos/trabajadores', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })

// La ficha de una persona: lo que tiene y las revistas que se le han hecho.
// Sirve igual para la propia (sin permiso de administrar) que para la de otro.
export const getTrabajador = (id) => api.get(`/encargos/trabajadores/${id}`)
export const getMisEncargos = () => api.get('/encargos/mios')

export const entregar     = (payload)     => api.post('/encargos', payload)
export const editarEncargo = (id, payload) => api.patch(`/encargos/${id}`, payload)
// Deja de estar a su cargo: devuelto, perdido o dado de baja.
export const cerrarEncargo = (id, payload) => api.post(`/encargos/${id}/cerrar`, payload)
export const borrarEncargo = (id)          => api.delete(`/encargos/${id}`)

// La revista: se manda entera, con todo lo que tiene a cargo contado.
export const guardarRevision = (payload) => api.post('/encargos/revisiones', payload)
export const getRevision     = (id)      => api.get(`/encargos/revisiones/${id}`)

// Cada cuántos días se revisa: el número general, o el propio de alguien.
export const guardarConfig = (payload) => api.put('/encargos/config', payload)
