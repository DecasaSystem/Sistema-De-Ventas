import api from './index'

export const getReceptores = () =>
  api.get('/consultas-costo/receptores')

export const getConsultas = () =>
  api.get('/consultas-costo')

export const crearConsulta = (data) =>
  api.post('/consultas-costo', data)

export const getConsulta = (id) =>
  api.get(`/consultas-costo/${id}`)

export const guardarItem = (id, itemId, data) =>
  api.put(`/consultas-costo/${id}/items/${itemId}`, data)

export const enviarConsulta = (id) =>
  api.post(`/consultas-costo/${id}/enviar`)
