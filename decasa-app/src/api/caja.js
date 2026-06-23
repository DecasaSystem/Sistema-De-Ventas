import api from '@/api'

export const getBalance = (tiendaId) =>
  api.get('/caja/balance', { params: tiendaId ? { tienda_id: tiendaId } : {} })

export const getMovimientos = (tiendaId, limite) =>
  api.get('/caja/movimientos', {
    params: {
      ...(tiendaId ? { tienda_id: tiendaId } : {}),
      ...(limite   ? { limite }              : {}),
    },
  })

export const registrarMovimiento = (data) =>
  api.post('/caja/movimiento', data)

export const eliminarMovimiento = (id) =>
  api.delete(`/caja/movimiento/${id}`)

export const getResumenTiendas = () =>
  api.get('/caja/resumen-tiendas')
