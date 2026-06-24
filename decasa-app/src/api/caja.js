import api from '@/api'

export const getBalance = (params = {}) =>
  api.get('/caja/balance', { params })

export const getMovimientos = (params = {}) =>
  api.get('/caja/movimientos', { params })

export const registrarMovimiento = (data) =>
  api.post('/caja/movimiento', data)

export const eliminarMovimiento = (id) =>
  api.delete(`/caja/movimiento/${id}`)

export const getResumenTiendas = () =>
  api.get('/caja/resumen-tiendas')
