import api from '@/api'

// Lista pública (usada en selectores de orden): excluye la fábrica.
export const getTiendas = () => api.get('/tiendas')

// Gestión (supervisor): trae todas, incluida la fábrica y las inactivas.
export const getTiendasAdmin  = () => api.get('/tiendas/admin')
export const crearTienda      = (payload) => api.post('/tiendas', payload)
export const actualizarTienda = (id, payload) => api.patch(`/tiendas/${id}`, payload)
export const eliminarTienda   = (id) => api.delete(`/tiendas/${id}`)
