import api from '@/api'

// Lo que volvió en el camión porque llegó dañado.
export const getDevoluciones = (params = {}) => api.get('/devoluciones', { params })

// Registrarla a mano: el cliente la trajo a la tienda, o el conductor no
// alcanzó a marcarla. El camino normal es el acta de entrega.
export const crearDevolucion = (payload) => api.post('/devoluciones', payload)

// Los dos caminos: 'a_produccion' (vuelve al taller a que la arreglen) o
// 'reembolso' (se cancela y se le devuelve la plata, con salida en Caja).
export const decidirDevolucion = (id, payload) => api.post(`/devoluciones/${id}/decidir`, payload)
