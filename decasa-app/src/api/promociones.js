import api from '@/api'

// Las que corren hoy en esa tienda: Nueva orden rebaja el precio con ellas.
export const getPromocionesVigentes = (tiendaId) =>
  api.get('/promociones/vigentes', { params: { tienda_id: tiendaId }, silencioso: true })

// Gestión (supervisor): Inventario → Promociones.
export const getPromociones       = () => api.get('/promociones')
export const crearPromocion       = (payload) => api.post('/promociones', payload)
export const actualizarPromocion  = (id, payload) => api.patch(`/promociones/${id}`, payload)
export const eliminarPromocion    = (id) => api.delete(`/promociones/${id}`)
