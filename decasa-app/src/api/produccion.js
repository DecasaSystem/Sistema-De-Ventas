import api from './index'

export const getProduccion = (params = {}) =>
  api.get('/produccion', { params })

export const updateProduccion = (id, data) =>
  api.patch(`/produccion/${id}`, data)

// Pasos de producción (ebanista / tapicero)
export const getMisPasos = () =>
  api.get('/produccion/mis-pasos')

export const getHistorialPasos = () =>
  api.get('/produccion/historial-pasos')

export const completarPaso = (pasoId, data) =>
  api.patch(`/produccion/pasos/${pasoId}/completar`, data)

// Despacho de producción (despachador)
export const getPendientesDespacho = () =>
  api.get('/produccion/pendientes-despacho')

export const getHistorialDespacho = () =>
  api.get('/produccion/historial-despacho')

export const completarDespacho = (produccionId) =>
  api.patch(`/produccion/${produccionId}/completar-despacho`)
