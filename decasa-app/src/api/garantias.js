import api from '@/api'

// Garantías: lo que se daña después de entregado (ver GarantiaService en el API).

// { estado: 'abiertas' | <estado>, orden_id }
export const getGarantias = (params = {}, config = {}) => api.get('/garantias', { params, ...config })

// Causales del anexo, procesos del taller y quién puede ir a domicilio.
export const getOpcionesGarantia = () => api.get('/garantias/opciones')

// Cuántos libres hay de un producto en cada tienda, con sus telas/opciones.
export const getStockParaCambio = (params) => api.get('/garantias/stock', { params })

export const reportarGarantia = (payload) => api.post('/garantias', payload)

// decision: taller | domicilio | cambio_mismo | cambio_otro | no_procede
export const decidirGarantia = (id, payload) => api.post(`/garantias/${id}/decidir`, payload)

// El mueble llegó a la fábrica: arranca el arreglo.
export const recibirGarantiaEnTaller = (id) => api.post(`/garantias/${id}/recibir`)

// Lo arreglaron en la casa del cliente.
export const registrarVisitaGarantia = (id, payload) => api.post(`/garantias/${id}/visita`, payload)

// Llegó lo que devolvió el cliente: sale la plata (solo supervisor).
export const recibirDevolucionGarantia = (id, payload) => api.post(`/garantias/${id}/recibir-devolucion`, payload)
