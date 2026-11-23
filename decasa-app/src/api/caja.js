import api from '@/api'

export const getBalance = (params = {}) =>
  api.get('/caja/balance', { params })

export const getMovimientos = (params = {}) =>
  api.get('/caja/movimientos', { params })

export const registrarMovimiento = (data) =>
  api.post('/caja/movimiento', data)

// Anula (no borra): deja de sumar y queda a la vista con el motivo.
export const eliminarMovimiento = (id, motivo = null) =>
  api.delete(`/caja/movimiento/${id}`, { data: { motivo } })

export const getResumenTiendas = () =>
  api.get('/caja/resumen-tiendas')
