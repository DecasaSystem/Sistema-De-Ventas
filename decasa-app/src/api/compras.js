import api from '@/api'

export const getCompras = (estado) => api.get('/compras', { params: estado ? { estado } : {} })
export const crearCompra = (payload) => api.post('/compras', payload)
export const marcarComprado = (id, payload) => api.patch(`/compras/${id}/comprar`, payload)
export const eliminarCompra = (id) => api.delete(`/compras/${id}`)
