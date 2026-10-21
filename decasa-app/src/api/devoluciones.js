import api from '@/api'

// Lo que volvió en el camión porque llegó dañado.
export const getDevoluciones = (params = {}) => api.get('/devoluciones', { params })

// Registrarla a mano: el cliente la trajo a la tienda, o el conductor no
// alcanzó a marcarla. El camino normal es el acta de entrega.
export const crearDevolucion = (payload) => api.post('/devoluciones', payload)

// Los dos caminos: 'a_produccion' (vuelve al taller a que la arreglen) o
// 'reembolso' (se cancela y se le devuelve la plata, con salida en Caja).
export const decidirDevolucion = (id, payload) => api.post(`/devoluciones/${id}/decidir`, payload)

// ── Cambio de producto: lo que el cliente devuelve y falta recoger ─────────
// A dónde puede ir: las tiendas abiertas y la fábrica/bodega.
export const getDestinosDevolucion = () => api.get('/devoluciones/destinos')

// Lo que hay que traer de la casa de un cliente (Despacho → Por recoger).
export const getPorRecoger = () => api.get('/devoluciones/por-recoger', { silencioso: true })

// Llegó: entra al inventario. { destino?: 'tienda'|'merma', tienda_id? } para cambiar a dónde va.
export const recibirDevolucion = (id, payload = {}) => api.post(`/devoluciones/${id}/recibir`, payload)

// { recogida: 'programada'|'con_entrega', fecha_recogida? }
export const reprogramarRecogida = (id, payload) => api.patch(`/devoluciones/${id}/recogida`, payload)
