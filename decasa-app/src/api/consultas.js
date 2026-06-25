import api from './index'

export const getReceptores = () =>
  api.get('/consultas-costo/receptores')

export const getConsultas = () =>
  api.get('/consultas-costo')

export const getConsultasMonitoreo = () =>
  api.get('/consultas-costo', { params: { monitoreo: 1 } })

export const crearConsulta = (data) =>
  api.post('/consultas-costo', data)

export const getConsulta = (id) =>
  api.get(`/consultas-costo/${id}`)

export const guardarItem = (id, itemId, data) =>
  api.put(`/consultas-costo/${id}/items/${itemId}`, data)

export const enviarConsulta = (id) =>
  api.post(`/consultas-costo/${id}/enviar`)

export const ajustarPrecio = (id, data) =>
  api.patch(`/consultas-costo/${id}/ajustar-precio`, data)

export const getMensajes = (id) =>
  api.get(`/consultas-costo/${id}/mensajes`)

export const enviarMensaje = (id, mensaje) =>
  api.post(`/consultas-costo/${id}/mensajes`, { mensaje })
