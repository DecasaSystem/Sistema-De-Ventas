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

export const devolverPaso = (pasoId, data) =>
  api.patch(`/produccion/pasos/${pasoId}/devolver`, data)

// A quién se puede poner en un paso, con su puntuación del taller. La línea
// —restauración o mueble nuevo— es lo que decide quiénes salen de primeros
// cuando el taller las lleva aparte.
export const getTrabajadoresTaller = (proceso, linea) =>
  api.get('/produccion/trabajadores', { params: { proceso, linea } })

// Apuntar quién está haciendo el paso, sin cerrarlo todavía.
export const asignarTrabajadoresPaso = (pasoId, trabajadores) =>
  api.patch(`/produccion/pasos/${pasoId}/trabajadores`, { trabajadores })
