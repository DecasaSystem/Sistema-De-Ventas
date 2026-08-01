import api from './index'

export const getCotizaciones = (params = {}) =>
  api.get('/cotizaciones', { params })

export const getCotizacion = (id) =>
  api.get(`/cotizaciones/${id}`)

export const crearCotizacion = (data) =>
  api.post('/cotizaciones', data)

export const cambiarEstadoCotizacion = (id, data) =>
  api.patch(`/cotizaciones/${id}/estado`, data)

export const eliminarCotizacion = (id) =>
  api.delete(`/cotizaciones/${id}`)

/** Avisa de precios cambiados y falta de stock antes de convertir. */
export const verificarCotizacion = (id) =>
  api.post(`/cotizaciones/${id}/verificar`)

export const convertirCotizacion = (id, data) =>
  api.post(`/cotizaciones/${id}/convertir`, data)

export const descargarPdfCotizacion = (id) =>
  api.get(`/cotizaciones/${id}/pdf`, { responseType: 'blob' })

/** Manda la cotización por correo, con el PDF adjunto. */
export const enviarCotizacionEmail = (id, email) =>
  api.post(`/cotizaciones/${id}/enviar`, email ? { email } : {})
